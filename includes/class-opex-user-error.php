<?php
/**
 * An error whose message is written for the person running the export.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a limit or a choice the person made stops an export, such as
 * an Excel sheet running out of rows. Its message is translated, holds no
 * server detail, and tells the person what to do. Every other exception
 * reaches the browser only as a generic message.
 */
class OPEX_User_Error extends RuntimeException {
}
