<?php
/**
 * Tests for the note body save path.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Notes\Admin;

use Brain\Monkey\Functions;
use HealthPress\Notes\Admin\Body_Metabox;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * `map_body()` runs on `wp_insert_post_data`, which is the last point before
 * the row is written, and it runs on *every* post save on the site. Most of
 * these tests therefore assert that it declines to act: on another post type,
 * without its nonce, or without its field. A false positive here would
 * overwrite the content of an unrelated post with an empty string.
 *
 * @covers \HealthPress\Notes\Admin\Body_Metabox
 */
final class BodyMetaboxTest extends TestCase {

	/**
	 * Stubs the WordPress functions the mapper reaches for.
	 *
	 * `wp_verify_nonce` and `current_user_can` are stubbed permissive, so the
	 * refusal tests below prove the mapper *asks* — they flip one stub and
	 * assert the answer is obeyed. What they cannot prove is that the nonce
	 * action or the capability name is the right one; only the integration
	 * suite, against real WordPress, can say that.
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->alias(
			static fn ( string $value ): string => self::sanitize_like_wordpress( $value )
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = array();
	}

	/**
	 * Clears the superglobal so one test cannot leak into the next.
	 */
	protected function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * A port of `sanitize_textarea_field()`, the sanitiser the mapper calls.
	 *
	 * The unit suite loads Brain Monkey rather than WordPress, and an
	 * approximation such as `strip_tags()` would let the encoding assertion
	 * below pass for the wrong reason — it is precisely the difference between
	 * the two that the assertion pins. This mirrors
	 * `_sanitize_text_fields( $str, true )` and the helpers it reaches
	 * (`wp_pre_kses_less_than()`, `wp_strip_all_tags()`, `esc_html()`) from
	 * wp-includes/formatting.php.
	 *
	 * Two knowing simplifications: `wp_check_invalid_utf8()` is omitted, and
	 * `esc_html()`'s entity normalisation is left to `htmlspecialchars()`'s own
	 * `$double_encode = false`. Neither is reachable from the inputs asserted
	 * here, and the port was diffed against the real function on this site over
	 * a corpus covering all of them.
	 *
	 * @param string $value The submitted body.
	 */
	private static function sanitize_like_wordpress( string $value ): string {
		if ( str_contains( $value, '<' ) ) {
			// wp_pre_kses_less_than(): a `<` that opens no tag becomes `&lt;`.
			$value = (string) preg_replace_callback(
				'%<[^>]*?((?=<)|>|$)%',
				static fn ( array $matches ): string => str_contains( $matches[0], '>' )
					? $matches[0]
					: htmlspecialchars( $matches[0], ENT_QUOTES, 'UTF-8', false ),
				$value
			);

			// wp_strip_all_tags(), which trims as well as stripping.
			$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $value );
			$value = trim( strip_tags( $value ) );

			$value = str_replace( "<\n", "&lt;\n", $value );
		}

		$value = trim( $value );

		// Percent-encoded characters, removed and then the gaps closed up.
		$found = false;

		while ( preg_match( '/%[a-f0-9]{2}/i', $value, $match ) ) {
			$value = str_replace( $match[0], '', $value );
			$found = true;
		}

		if ( $found ) {
			$value = trim( (string) preg_replace( '/ +/', ' ', $value ) );
		}

		return $value;
	}

	/**
	 * Fills $_POST as the edit form would.
	 *
	 * @param string $body The submitted body.
	 */
	private function submit( string $body ): void {
		$_POST = array(
			'hp_note_body'       => $body,
			'hp_note_body_nonce' => 'a-valid-nonce',
		);
	}

	public function test_it_writes_the_body_to_post_content(): void {
		$this->submit( "Dr: how are you?\nMe: fine." );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => '',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( "Dr: how are you?\nMe: fine.", $data['post_content'] );
	}

	/**
	 * The body is plain text by definition, so tags are stripped rather than
	 * filtered — nothing stored can then be rendered as markup.
	 */
	public function test_it_strips_tags_from_the_body(): void {
		$this->submit( 'Note <script>alert(1)</script> text' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => '',
			),
			array( 'ID' => 7 )
		);

		$this->assertStringNotContainsString( '<script>', $data['post_content'] );
	}

	/**
	 * Pins the cost of `sanitize_textarea_field()`, which is not byte-exact:
	 * clinical shorthand like `HbA1c <5.7%` is stored HTML-encoded. Documented
	 * as a test so it is a known property rather than a surprise found on a
	 * real note.
	 */
	public function test_it_encodes_a_bare_less_than_sign(): void {
		$this->submit( 'HbA1c <5.7%' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => '',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'HbA1c &lt;5.7%', $data['post_content'] );
	}

	public function test_it_ignores_another_post_type(): void {
		$this->submit( 'Should not land anywhere.' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'post',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'Original.', $data['post_content'] );
	}

	public function test_it_ignores_a_save_that_carries_no_body_field(): void {
		$_POST = array( 'hp_note_body_nonce' => 'a-valid-nonce' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'Original.', $data['post_content'] );
	}

	public function test_it_ignores_a_save_with_no_nonce(): void {
		$_POST = array( 'hp_note_body' => 'Should not land anywhere.' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'Original.', $data['post_content'] );
	}

	public function test_it_ignores_a_save_whose_nonce_does_not_verify(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$this->submit( 'Should not land anywhere.' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'Original.', $data['post_content'] );
	}

	public function test_it_ignores_a_user_who_cannot_edit_the_post(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->submit( 'Should not land anywhere.' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( 'Original.', $data['post_content'] );
	}

	/**
	 * Emptying the textarea deliberately has to be possible, so an empty
	 * submission is a write of '' rather than a no-op. This is the one case
	 * where "no content" is intentional, which is why the guards above key off
	 * `isset()` on the field rather than on its being non-empty.
	 */
	public function test_it_allows_the_body_to_be_emptied(): void {
		$this->submit( '' );

		$data = ( new Body_Metabox() )->map_body(
			array(
				'post_type'    => 'hp_note',
				'post_content' => 'Original.',
			),
			array( 'ID' => 7 )
		);

		$this->assertSame( '', $data['post_content'] );
	}
}
