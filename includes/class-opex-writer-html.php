<?php
/**
 * Streaming HTML writer: one self-contained page with a table that prints
 * well. No script, no remote asset, and every cell escaped.
 *
 * Like the other writers it holds no state between calls. start() writes the
 * page head and the header row, append() adds rows, finish() closes the page.
 * Each call opens the file in append mode, so a new PHP process can continue,
 * and the base class checkpoint (the file size) lets a batch replay safely.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Writes the export as an HTML table.
 */
class OPEX_Writer_HTML extends OPEX_Writer {

	/**
	 * Page title, from the export name.
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Sets up the writer.
	 *
	 * @param string $path    Output file path.
	 * @param array  $headers Field id => column label.
	 * @param string $title   Export name for the page title. Only start() uses it.
	 */
	public function __construct( string $path, array $headers, string $title = '' ) {
		parent::__construct( $path, $headers );
		$this->title = '' !== trim( $title ) ? $title : 'Export';
	}

	/**
	 * Writes the page head, the heading and the header row.
	 */
	public function start(): void {
		$title = self::cell( $this->title );
		$head  = '';
		foreach ( $this->headers as $label ) {
			$head .= '<th scope="col">' . self::cell( (string) $label ) . '</th>';
		}
		$this->write(
			"<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<title>' . $title . "</title>\n"
			. '<style>' . self::style() . "</style>\n"
			. "</head>\n<body>\n"
			. '<h1>' . $title . "</h1>\n"
			. "<table>\n<thead>\n<tr>" . $head . "</tr>\n</thead>\n<tbody>\n"
		);
	}

	/**
	 * Adds one batch of rows.
	 *
	 * @param array $rows        Rows keyed by field id.
	 * @param bool  $first_batch Unused.
	 */
	public function append( array $rows, bool $first_batch ): void {
		if ( ! $rows ) {
			return;
		}
		$out = '';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( array_keys( $this->headers ) as $field ) {
				$out .= '<td>' . self::cell( (string) ( $row[ $field ] ?? '' ) ) . '</td>';
			}
			$out .= "</tr>\n";
		}
		$this->write( $out );
	}

	/**
	 * Closes the table and the page.
	 */
	public function finish(): void {
		$this->write( "</tbody>\n</table>\n</body>\n</html>\n" );
	}

	/**
	 * One cell as safe HTML that shows exactly its text. Every & is encoded,
	 * even one that already starts an entity, so a cell holding "&amp;"
	 * shows "&amp;" and not "&" (esc_html would leave it as it is).
	 * Invalid UTF-8 is removed first, and control characters too. Line
	 * breaks become <br> so they show.
	 *
	 * @param string $value Raw text.
	 * @return string The cell as HTML.
	 */
	private static function cell( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		$safe  = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
		return str_replace( array( "\r\n", "\r", "\n" ), '<br>', $safe );
	}

	/**
	 * A small style sheet: readable on screen, and on paper the header row
	 * repeats on every page and no row splits across two pages.
	 *
	 * @return string The CSS text.
	 */
	private static function style(): string {
		return 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-size:14px;color:#1d2327;background:#fff;margin:24px}'
			. 'h1{font-size:20px;margin:0 0 16px}'
			. 'table{border-collapse:collapse;width:100%}'
			. 'th,td{border:1px solid #c3c4c7;padding:4px 8px;text-align:left;vertical-align:top}'
			. 'th{background:#f0f0f1}'
			. 'thead{display:table-header-group}'
			. 'tr{break-inside:avoid;page-break-inside:avoid}'
			. '@media print{body{margin:0;font-size:10pt}th{background:none}}';
	}

	/**
	 * Appends text to the output file, every byte or an error.
	 *
	 * @param string $text Text.
	 * @return void
	 * @throws OPEX_User_Error When the file cannot be written in full.
	 */
	private function write( string $text ): void {
		$this->append_text( $text );
	}
}
