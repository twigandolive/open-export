<?php
/**
 * Streaming file writers. Each is stateless between batches: the exporter
 * calls start() once, append() per batch, finish() once. Files are opened
 * in append mode so a job can resume after any interruption.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamed export output; WP_Filesystem cannot append to multi-gigabyte files across hundreds of requests.

abstract class OPEX_Writer {

	protected string $path;
	protected array $headers;

	public function __construct( string $path, array $headers ) {
		$this->path    = $path;
		$this->headers = $headers;
	}

	public static function make( string $format, string $path, array $headers ): OPEX_Writer {
		switch ( $format ) {
			case 'json':
				return new OPEX_Writer_JSON( $path, $headers );
			case 'xml':
				return new OPEX_Writer_XML( $path, $headers );
			default:
				return new OPEX_Writer_CSV( $path, $headers );
		}
	}

	abstract public function start(): void;

	/** @param array $rows list of assoc arrays keyed by field id. */
	abstract public function append( array $rows, bool $first_batch ): void;

	abstract public function finish(): void;

	protected function open() {
		$handle = fopen( $this->path, 'ab' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Could not open export file for writing.' );
		}
		return $handle;
	}
}

class OPEX_Writer_CSV extends OPEX_Writer {

	public function start(): void {
		$handle = $this->open();
		// UTF-8 BOM so Excel opens accented characters correctly.
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array_values( $this->headers ) );
		fclose( $handle );
	}

	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( array_keys( $this->headers ) as $field ) {
				$line[] = $this->guard( (string) ( $row[ $field ] ?? '' ) );
			}
			fputcsv( $handle, $line );
		}
		fclose( $handle );
	}

	public function finish(): void {}

	/**
	 * Neutralize spreadsheet formula injection: a cell starting with
	 * = + - @ or a tab would execute when opened in Excel/Sheets.
	 */
	private function guard( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}

class OPEX_Writer_JSON extends OPEX_Writer {

	public function start(): void {
		$handle = $this->open();
		fwrite( $handle, "[\n" );
		fclose( $handle );
	}

	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		$first  = $first_batch;
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( $this->headers as $field => $label ) {
				$item[ $label ] = (string) ( $row[ $field ] ?? '' );
			}
			fwrite( $handle, ( $first ? '' : ",\n" ) . '  ' . wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$first = false;
		}
		fclose( $handle );
	}

	public function finish(): void {
		$handle = $this->open();
		fwrite( $handle, "\n]\n" );
		fclose( $handle );
	}
}

class OPEX_Writer_XML extends OPEX_Writer {

	public function start(): void {
		$handle = $this->open();
		fwrite( $handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<records>\n" );
		fclose( $handle );
	}

	public function append( array $rows, bool $first_batch ): void {
		$handle = $this->open();
		foreach ( $rows as $row ) {
			fwrite( $handle, "  <record>\n" );
			foreach ( $this->headers as $field => $label ) {
				$el = $this->element_name( $label );
				fwrite( $handle, "    <{$el}>" . $this->escape( (string) ( $row[ $field ] ?? '' ) ) . "</{$el}>\n" );
			}
			fwrite( $handle, "  </record>\n" );
		}
		fclose( $handle );
	}

	public function finish(): void {
		$handle = $this->open();
		fwrite( $handle, "</records>\n" );
		fclose( $handle );
	}

	private function element_name( string $label ): string {
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $label );
		if ( '' === $name || ! preg_match( '/^[A-Za-z_]/', $name ) ) {
			$name = '_' . $name;
		}
		return $name;
	}

	private function escape( string $value ): string {
		// Strip control characters that are invalid in XML 1.0, then escape.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}

// phpcs:enable
