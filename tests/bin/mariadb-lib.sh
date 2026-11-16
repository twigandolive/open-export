#!/usr/bin/env bash
# Shared functions for the private MariaDB test run. Sourced by setup-site.sh and test.sh.
# Never touches the system MariaDB service or its data directory: every server
# started here has its own data directory, pid file and port inside the site folder.
# The unix socket lives behind a short symlink in /tmp, because macOS limits socket
# paths to 104 characters and site folders are usually deeper than that.

# Print the directory that holds the MariaDB 10.11 binaries, or fail.
opex_maria_bin() {
	local c
	for c in /opt/homebrew/opt/mariadb@10.11 /opt/homebrew/Cellar/mariadb@10.11/* /usr/local/opt/mariadb@10.11 /usr/local/Cellar/mariadb@10.11/*; do
		if [ -x "$c/bin/mariadbd" ] && [ -x "$c/bin/mariadb-install-db" ]; then
			printf '%s\n' "$c"
			return 0
		fi
	done
	echo "MariaDB 10.11 binaries not found under /opt/homebrew or /usr/local." >&2
	return 1
}

# Echo a free TCP port on 127.0.0.1.
opex_maria_free_port() {
	php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'
}

# Short socket directory (symlink to the site's mariadb folder).
opex_maria_sockdir() {
	local site="$1" h
	h="$(printf '%s' "$site" | shasum | cut -c1-8)"
	printf '/tmp/opexm-%s\n' "$h"
}

# Read a key from the site's mariadb.env.
opex_maria_env() {
	sed -n "s/^$2=//p" "$1/mariadb.env" | head -1
}

# Create a fresh data directory and write mariadb.env (called by setup-site.sh).
opex_maria_init() {
	local site="$1" base dir port
	base="$(opex_maria_bin)" || return 1
	dir="$site/mariadb"
	rm -rf "${dir:?}/data"
	mkdir -p "$dir/data"
	port="$(opex_maria_free_port)"
	"$base/bin/mariadb-install-db" --no-defaults --basedir="$base" --datadir="$dir/data" \
		--auth-root-authentication-method=normal --skip-test-db >"$dir/install.log" 2>&1 || {
		echo "mariadb-install-db failed, see $dir/install.log" >&2
		return 1
	}
	{
		echo "BASE=$base"
		echo "PORT=$port"
		echo "DBNAME=opex_test"
		echo "DBUSER=opex"
		echo "DBPASS=opex-test-pass"
	} >"$site/mariadb.env"
}

# True when the private server answers.
opex_maria_up() {
	local site="$1" base sock
	base="$(opex_maria_env "$site" BASE)"
	sock="$(opex_maria_sockdir "$site")/s.sock"
	"$base/bin/mariadb-admin" --no-defaults --socket="$sock" -uroot ping >/dev/null 2>&1
}

# Start the private server (idempotent). Two attempts, then fail with the log.
opex_maria_start() {
	local site="$1" base port dir sock sockdir attempt i
	[ -f "$site/mariadb.env" ] || { echo "No mariadb.env in $site" >&2; return 1; }
	base="$(opex_maria_env "$site" BASE)"
	port="$(opex_maria_env "$site" PORT)"
	dir="$site/mariadb"
	sockdir="$(opex_maria_sockdir "$site")"
	sock="$sockdir/s.sock"
	opex_maria_up "$site" && return 0
	ln -sfn "$dir" "$sockdir"
	for attempt in 1 2; do
		# The saved port may be taken by now: pick another and update the config.
		if ! php -r '$s = @stream_socket_server("tcp://127.0.0.1:" . $argv[1]); exit($s ? 0 : 1);' "$port"; then
			port="$(opex_maria_free_port)"
			sed -i.bak "s/^PORT=.*/PORT=$port/" "$site/mariadb.env" && rm -f "$site/mariadb.env.bak"
		fi
		rm -f "$dir/mariadb.pid" "$sock"
		nohup "$base/bin/mariadbd" --no-defaults --basedir="$base" --datadir="$dir/data" \
			--socket="$sock" --pid-file="$dir/mariadb.pid" --port="$port" --bind-address=127.0.0.1 \
			--skip-name-resolve --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_520_ci \
			--innodb-buffer-pool-size=128M --max-allowed-packet=64M --log-error="$dir/error.log" \
			>/dev/null 2>&1 &
		for i in $(seq 1 60); do
			opex_maria_up "$site" && break 2
			sleep 0.5
		done
	done
	if ! opex_maria_up "$site"; then
		echo "Private MariaDB did not start after two attempts. Last log lines:" >&2
		tail -15 "$dir/error.log" >&2 || true
		return 1
	fi
	# Keep wp-config in step with the port.
	if [ -f "$site/wordpress/wp-config.php" ]; then
		sed -i.bak -E "s#(define\( *'DB_HOST', *')[^']*(' *\))#\1127.0.0.1:$port\2#" "$site/wordpress/wp-config.php" && rm -f "$site/wordpress/wp-config.php.bak"
	fi
}

# Stop the private server and remove the socket symlink (idempotent).
opex_maria_stop() {
	local site="$1" base sock sockdir i
	[ -f "$site/mariadb.env" ] || return 0
	base="$(opex_maria_env "$site" BASE)"
	sockdir="$(opex_maria_sockdir "$site")"
	sock="$sockdir/s.sock"
	if opex_maria_up "$site"; then
		"$base/bin/mariadb-admin" --no-defaults --socket="$sock" -uroot shutdown >/dev/null 2>&1 || true
		for i in $(seq 1 60); do
			opex_maria_up "$site" || break
			sleep 0.5
		done
	fi
	[ -L "$sockdir" ] && rm -f "$sockdir"
	return 0
}

# Create the WordPress database and user (server must be running).
opex_maria_create_db() {
	local site="$1" base sock name user pass
	base="$(opex_maria_env "$site" BASE)"
	sock="$(opex_maria_sockdir "$site")/s.sock"
	name="$(opex_maria_env "$site" DBNAME)"
	user="$(opex_maria_env "$site" DBUSER)"
	pass="$(opex_maria_env "$site" DBPASS)"
	"$base/bin/mariadb" --no-defaults --socket="$sock" -uroot -e \
		"CREATE DATABASE \`$name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; CREATE USER '$user'@'%' IDENTIFIED BY '$pass'; CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$pass'; GRANT ALL ON \`$name\`.* TO '$user'@'%'; GRANT ALL ON \`$name\`.* TO '$user'@'127.0.0.1'; FLUSH PRIVILEGES;"
}
