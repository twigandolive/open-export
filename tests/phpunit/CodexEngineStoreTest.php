<?php
/**
 * R1-1.1, R1-1.9 and R1-5.1: a change to a stored option never lands over a
 * newer value, and settings and templates go through that locked change.
 * The other process is played by writes straight into the database, the
 * way a second PHP process would write.
 *
 * @package Open_Export
 */

use PHPUnit\Framework\TestCase;

class CodexEngineStoreTest extends TestCase {

	private const OPTION = 'opex_codex_store_test';

	/** Template ids to remove. */
	private array $templates = array();

	/** Settings as they were before the test. */
	private $settings = null;

	protected function setUp(): void {
		$this->settings = get_option( 'opex_settings', null );
	}

	protected function tearDown(): void {
		delete_option( self::OPTION );
		OPEX_Lock::force_release( 'store_' . self::OPTION );
		$this->set_wait( 20 );
		foreach ( $this->templates as $id ) {
			OPEX_Templates::delete( $id );
		}
		if ( null === $this->settings ) {
			delete_option( 'opex_settings' );
		} else {
			update_option( 'opex_settings', $this->settings, false );
		}
	}

	/**
	 * Changes the wait of OPEX_Store::take(), so a test of a lock that cannot
	 * be had does not wait the full 20 seconds.
	 *
	 * @param float $seconds Seconds.
	 */
	private function set_wait( float $seconds ): void {
		$prop = new ReflectionProperty( OPEX_Store::class, 'wait' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, $seconds );
	}

	/**
	 * Writes the option row the way another process would.
	 *
	 * @param string   $option Option name.
	 * @param callable $edit   Gets the stored value and returns the new one.
	 */
	private function write_elsewhere( string $option, callable $edit ): void {
		global $wpdb;
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$value = $edit( null === $raw ? array() : maybe_unserialize( (string) $raw ) );
		if ( null === $raw ) {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $option,
					'option_value' => maybe_serialize( $value ),
					'autoload'     => 'off',
				)
			);
			return;
		}
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => $option ) );
	}

	/** The row as the database holds it. */
	private function stored( string $option ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		return null === $raw ? null : maybe_unserialize( (string) $raw );
	}

	public function test_a_change_whose_lock_expired_does_not_overwrite_the_newer_save() {
		update_option( self::OPTION, array( 'start' => 1 ), false );
		$runs   = 0;
		$result = OPEX_Store::change(
			self::OPTION,
			function ( array &$value ) use ( &$runs ) {
				++$runs;
				if ( 1 === $runs ) {
					// The lease ran out during a slow change. Another process took
					// the lock, saved, and let it go.
					OPEX_Lock::force_release( 'store_' . self::OPTION );
					$theirs = OPEX_Lock::acquire( 'store_' . self::OPTION, 15 );
					$this->assertIsString( $theirs );
					$this->write_elsewhere(
						self::OPTION,
						static function ( $stored ) {
							$stored['theirs'] = 'saved';
							return $stored;
						}
					);
					OPEX_Lock::release( 'store_' . self::OPTION, $theirs );
				}
				$value['mine'] = 'saved';
				return 'done';
			}
		);

		$this->assertSame( 'done', $result );
		$this->assertSame( 2, $runs, 'The change runs again on the fresh value.' );
		$this->assertSame(
			array(
				'start'  => 1,
				'theirs' => 'saved',
				'mine'   => 'saved',
			),
			$this->stored( self::OPTION )
		);
		$this->assertFalse( OPEX_Lock::is_held( 'store_' . self::OPTION ) );
	}

	public function test_a_write_that_skipped_the_lock_is_not_overwritten() {
		update_option( self::OPTION, array( 'start' => 1 ), false );
		$runs = 0;
		OPEX_Store::change(
			self::OPTION,
			function ( array &$value ) use ( &$runs ) {
				++$runs;
				if ( 1 === $runs ) {
					$this->write_elsewhere(
						self::OPTION,
						static function ( $stored ) {
							$stored['theirs'] = 'saved';
							return $stored;
						}
					);
				}
				$value['mine'] = $runs;
			}
		);

		$stored = $this->stored( self::OPTION );
		$this->assertSame( 'saved', $stored['theirs'] ?? null, 'The newer value must survive.' );
		$this->assertSame( 2, $stored['mine'] ?? null );
	}

	public function test_a_first_write_creates_the_option_once() {
		$this->assertNull( $this->stored( self::OPTION ) );
		$runs = 0;
		OPEX_Store::change(
			self::OPTION,
			function ( array &$value ) use ( &$runs ) {
				++$runs;
				if ( 1 === $runs ) {
					$this->write_elsewhere(
						self::OPTION,
						static function () {
							return array( 'theirs' => 'saved' );
						}
					);
				}
				$value['mine'] = 'saved';
			}
		);
		$this->assertSame(
			array(
				'theirs' => 'saved',
				'mine'   => 'saved',
			),
			$this->stored( self::OPTION )
		);
		$this->assertSame(
			array(
				'theirs' => 'saved',
				'mine'   => 'saved',
			),
			OPEX_Store::read( self::OPTION ),
			'A read after the change sees the saved value, not a cached one.'
		);
	}

	public function test_a_change_that_never_wins_gives_up_without_writing() {
		update_option( self::OPTION, array( 'n' => 0 ), false );
		$runs  = 0;
		$error = null;
		try {
			OPEX_Store::change(
				self::OPTION,
				function ( array &$value ) use ( &$runs ) {
					++$runs;
					$this->write_elsewhere(
						self::OPTION,
						static function ( $stored ) {
							$stored['n'] = (int) $stored['n'] + 1;
							return $stored;
						}
					);
					$value['mine'] = 'lost';
				}
			);
		} catch ( Throwable $e ) {
			$error = $e;
		}

		$this->assertInstanceOf( OPEX_User_Error::class, $error );
		$this->assertTrue( OPEX_Store::is_busy( $error ) );
		$this->assertSame( 3, $runs );
		$this->assertSame( array( 'n' => 3 ), $this->stored( self::OPTION ), 'Nothing of the failed change was written.' );
		$this->assertFalse( OPEX_Lock::is_held( 'store_' . self::OPTION ) );
	}

	public function test_a_lock_that_cannot_be_had_never_leads_to_an_unlocked_write() {
		update_option( self::OPTION, array( 'start' => 1 ), false );
		$theirs = OPEX_Lock::acquire( 'store_' . self::OPTION, 300 );
		$this->assertIsString( $theirs );
		$this->set_wait( 0.2 );
		$ran   = false;
		$error = null;
		try {
			OPEX_Store::change(
				self::OPTION,
				static function ( array &$value ) use ( &$ran ) {
					$ran           = true;
					$value['mine'] = 'saved';
				}
			);
		} catch ( Throwable $e ) {
			$error = $e;
		}
		OPEX_Lock::release( 'store_' . self::OPTION, $theirs );

		$this->assertNotNull( $error, 'A change must not write without the lock.' );
		$this->assertTrue( OPEX_Store::is_busy( $error ) );
		$this->assertFalse( $ran );
		$this->assertSame( array( 'start' => 1 ), $this->stored( self::OPTION ) );
	}

	public function test_a_nested_conflict_runs_the_outer_change_again() {
		update_option( self::OPTION, array( 'start' => 1 ), false );
		$outer = 0;
		OPEX_Store::change(
			self::OPTION,
			function ( array &$value ) use ( &$outer ) {
				++$outer;
				OPEX_Store::change(
					self::OPTION,
					function ( array &$inner ) use ( $outer ) {
						if ( 1 === $outer ) {
							$this->write_elsewhere(
								self::OPTION,
								static function ( $stored ) {
									$stored['theirs'] = 'saved';
									return $stored;
								}
							);
						}
						$inner['inner'] = $outer;
					}
				);
			}
		);
		$stored = $this->stored( self::OPTION );
		$this->assertSame( 2, $outer );
		$this->assertSame( 'saved', $stored['theirs'] ?? null );
		$this->assertSame( 2, $stored['inner'] ?? null );
	}

	public function test_settings_saves_keep_a_setting_another_process_saved() {
		OPEX_Settings::save( array( 'retention_days' => 30, 'link_days' => 7 ) );
		OPEX_Settings::all();
		get_option( 'opex_settings' );
		$this->write_elsewhere(
			'opex_settings',
			static function ( $stored ) {
				$stored['retention_days'] = 90;
				return $stored;
			}
		);

		$out = OPEX_Settings::save( array( 'link_days' => 14 ) );

		$this->assertSame( 14, $out['link_days'] );
		$this->assertSame( 90, $out['retention_days'], 'The other request saved 90 days: it must stay.' );
		$this->assertSame( 90, $this->stored( 'opex_settings' )['retention_days'] );
	}

	public function test_template_saves_keep_a_template_another_process_saved() {
		$config = array(
			'source' => 'posts:post',
			'fields' => array( 'core:ID' ),
		);
		$first  = OPEX_Templates::save( 'Codex store one', $config );
		$this->assertIsArray( $first );
		$this->templates[] = $first['id'];
		OPEX_Templates::all();
		get_option( 'opex_templates' );

		$this->write_elsewhere(
			'opex_templates',
			static function ( $stored ) use ( $first ) {
				$copy                   = $stored[ $first['id'] ];
				$copy['id']             = 'tcodexstore01';
				$copy['name']           = 'Codex store other';
				$stored['tcodexstore01'] = $copy;
				return $stored;
			}
		);
		$this->templates[] = 'tcodexstore01';

		$second = OPEX_Templates::save( 'Codex store two', $config );
		$this->assertIsArray( $second );
		$this->templates[] = $second['id'];

		$stored = $this->stored( 'opex_templates' );
		$this->assertArrayHasKey( 'tcodexstore01', $stored, 'A template saved by another request must stay.' );
		$this->assertArrayHasKey( $second['id'], $stored );
	}

	public function test_a_template_delete_keeps_a_template_another_process_saved() {
		$config = array(
			'source' => 'posts:post',
			'fields' => array( 'core:ID' ),
		);
		$first  = OPEX_Templates::save( 'Codex store delete', $config );
		$this->assertIsArray( $first );
		$this->templates[] = $first['id'];
		OPEX_Templates::all();
		get_option( 'opex_templates' );

		$this->write_elsewhere(
			'opex_templates',
			static function ( $stored ) use ( $first ) {
				$copy                    = $stored[ $first['id'] ];
				$copy['id']              = 'tcodexstore02';
				$stored['tcodexstore02'] = $copy;
				return $stored;
			}
		);
		$this->templates[] = 'tcodexstore02';

		OPEX_Templates::delete( $first['id'] );

		$stored = $this->stored( 'opex_templates' );
		$this->assertArrayNotHasKey( $first['id'], $stored );
		$this->assertArrayHasKey( 'tcodexstore02', $stored, 'A delete must not drop a template another request saved.' );
	}
}
