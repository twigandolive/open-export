"""Throwaway SFTP server for Open Export tests. Binds to 127.0.0.1 only.

Usage: sftp_server.py <root> <port> <user> <password> <authorized_keys_file>
Prints READY on stdout when it accepts connections.
"""
import asyncio
import sys

import asyncssh

root, port, user, password, authkeys = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]


class Server(asyncssh.SSHServer):
    def begin_auth(self, username):
        return True

    def password_auth_supported(self):
        return True

    def validate_password(self, username, pw):
        return username == user and pw == password

    def public_key_auth_supported(self):
        return True


def make_sftp(chan):
    return asyncssh.SFTPServer(chan, chroot=root)


async def main():
    await asyncssh.listen(
        "127.0.0.1",
        port,
        server_factory=Server,
        server_host_keys=[asyncssh.generate_private_key("ssh-ed25519")],
        authorized_client_keys=authkeys,
        sftp_factory=make_sftp,
        allow_scp=False,
    )
    print("READY", flush=True)
    await asyncio.Event().wait()


asyncio.run(main())
