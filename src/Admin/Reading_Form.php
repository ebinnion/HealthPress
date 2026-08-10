<?php
/**
 * The Reading metabox.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Storage\Reading;
use HealthPress\Storage\Reading_Repository;
use HealthPress\Support\Unit_Registry;
use WP_Post;

/**
 * Renders the one box the reading editor consists of.
 *
 * Every metric's field group is rendered and JavaScript toggles between them.
 * Inactive groups are hidden *and* disabled — and the disabling is required,
 * not an optimisation: a hidden `required` input blocks form submission
 * outright, because the browser cannot focus it to report the error. Disabled
 * controls are exempt from constraint validation and are not submitted at all.
 */
final class Reading_Form {

	/**
	 * Wires what the form needs to render.
	 *
	 * @param Metric_Registry    $metrics  The metric catalog.
	 * @param Unit_Registry      $units    Resolves a field's unit to its written form.
	 * @param Reading_Repository $readings Used to load the stored reading, if any.
	 */
	public function __construct(
		private readonly Metric_Registry $metrics,
		private readonly Unit_Registry $units,
		private readonly Reading_Repository $readings,
	) {}

	/**
	 * The written abbreviation to show beside a field, if it has one.
	 *
	 * Falls back to the slug for a unit the catalog does not know, which should
	 * not happen but is better than rendering nothing at all.
	 *
	 * @param Field $field The field being rendered.
	 */
	private function unit_label( Field $field ): string {
		if ( ! $field->has_unit() ) {
			return '';
		}

		return $this->units->has( $field->unit )
			? $this->units->get( $field->unit )->label
			: $field->unit;
	}

	/**
	 * Renders the metabox.
	 *
	 * @param WP_Post                  $post     The reading being edited.
	 * @param Rejected_Submission|null $rejected A refused submission to restore, if any.
	 */
	public function render( WP_Post $post, ?Rejected_Submission $rejected = null ): void {
		wp_nonce_field( Reading_Save_Handler::NONCE_ACTION . $post->ID, Reading_Save_Handler::NONCE_FIELD );

		$stored = $this->readings->get( $post->ID );
		$stored = $stored instanceof Reading ? $stored : null;

		$selected = $this->selected_metric( $rejected, $stored );
		$note     = $this->note( $rejected, $stored, $post );

		$this->render_metric_selector( $selected );

		foreach ( $this->metrics->all() as $metric ) {
			$this->render_value_group( $metric, $metric->slug === $selected, $rejected, $stored );
		}

		$this->render_note( $note );

		?>
		<noscript>
			<p class="description">
				<?php esc_html_e( 'With JavaScript disabled the fields shown belong to the selected metric only. Save, then reopen the reading to change metric.', 'healthpress' ); ?>
			</p>
		</noscript>
		<?php
	}

	/**
	 * Renders the metric chooser.
	 *
	 * A single-select, never checkboxes: a reading measures exactly one thing.
	 *
	 * @param string $selected The currently chosen metric slug.
	 */
	private function render_metric_selector( string $selected ): void {
		?>
		<p class="hp-field">
			<label for="hp-metric"><strong><?php esc_html_e( 'Metric', 'healthpress' ); ?></strong></label><br />
			<select id="hp-metric" name="hp[metric]" required>
				<?php foreach ( $this->metrics->all() as $metric ) : ?>
					<option value="<?php echo esc_attr( $metric->slug ); ?>" <?php selected( $metric->slug, $selected ); ?>>
						<?php echo esc_html( $metric->label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Renders one metric's value fields.
	 *
	 * @param Metric_Type              $metric   The metric to render.
	 * @param bool                     $active   Whether this is the selected metric.
	 * @param Rejected_Submission|null $rejected A refused submission to restore, if any.
	 * @param Reading|null             $stored   The stored reading, if any.
	 */
	private function render_value_group( Metric_Type $metric, bool $active, ?Rejected_Submission $rejected, ?Reading $stored ): void {
		?>
		<div class="hp-values" data-metric="<?php echo esc_attr( $metric->slug ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
			<?php foreach ( $metric->fields as $field ) : ?>
				<?php $this->render_field( $metric, $field, $active, $rejected, $stored ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders one value input.
	 *
	 * @param Metric_Type              $metric   The metric the field belongs to.
	 * @param Field                    $field    The field to render.
	 * @param bool                     $active   Whether its metric is selected.
	 * @param Rejected_Submission|null $rejected A refused submission to restore, if any.
	 * @param Reading|null             $stored   The stored reading, if any.
	 */
	private function render_field( Metric_Type $metric, Field $field, bool $active, ?Rejected_Submission $rejected, ?Reading $stored ): void {
		$id    = sprintf( 'hp-%s-%s', $metric->slug, $field->key );
		$value = $this->field_value( $metric, $field, $rejected, $stored );
		$unit  = $this->unit_label( $field );

		$attributes = Field_Attributes::for( $field );

		/*
		 * Values are nested under the metric slug because five metrics declare
		 * a field called `value`, and every group is rendered — a flat name
		 * would collide across them.
		 */
		$name = sprintf( 'hp[values][%s][%s]', $metric->slug, $field->key );

		?>
		<p class="hp-field">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $field->label ); ?>
				<?php if ( '' !== $unit ) : ?>
					<span class="hp-unit">(<?php echo esc_html( $unit ); ?>)</span>
				<?php endif; ?>
				<?php if ( ! $field->required ) : ?>
					<span class="hp-optional"><?php esc_html_e( '— optional', 'healthpress' ); ?></span>
				<?php endif; ?>
			</label><br />
			<input
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				<?php echo $active ? '' : 'disabled'; ?>
				<?php
				foreach ( $attributes as $attribute => $attribute_value ) {
					printf( ' %s="%s"', esc_attr( $attribute ), esc_attr( $attribute_value ) );
				}
				?>
			/>
			<?php if ( null !== $field->description ) : ?>
				<span class="description"><?php echo esc_html( $field->description ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Renders the note field.
	 *
	 * @param string $note The note to show.
	 */
	private function render_note( string $note ): void {
		?>
		<p class="hp-field">
			<label for="hp-note"><strong><?php esc_html_e( 'Note', 'healthpress' ); ?></strong></label><br />
			<textarea id="hp-note" name="hp[note]" rows="3" class="large-text"><?php echo esc_textarea( $note ); ?></textarea>
		</p>
		<?php
	}

	// -----------------------------------------------------------------
	// Repopulation. A refused submission wins over stored values, so nothing
	// has to be retyped — including the number that needs correcting.
	// -----------------------------------------------------------------

	/**
	 * Resolves which metric should be selected.
	 *
	 * @param Rejected_Submission|null $rejected A refused submission, if any.
	 * @param Reading|null             $stored   The stored reading, if any.
	 */
	private function selected_metric( ?Rejected_Submission $rejected, ?Reading $stored ): string {
		if ( null !== $rejected && $this->metrics->has( $rejected->metric() ) ) {
			return $rejected->metric();
		}

		if ( null !== $stored ) {
			return $stored->metric->slug;
		}

		return $this->metrics->slugs()[0] ?? '';
	}

	/**
	 * Resolves the value to show in a field.
	 *
	 * @param Metric_Type              $metric   The metric the field belongs to.
	 * @param Field                    $field    The field being rendered.
	 * @param Rejected_Submission|null $rejected A refused submission, if any.
	 * @param Reading|null             $stored   The stored reading, if any.
	 */
	private function field_value( Metric_Type $metric, Field $field, ?Rejected_Submission $rejected, ?Reading $stored ): string {
		if ( null !== $rejected ) {
			return $rejected->value_for( $metric->slug, $field->key );
		}

		if ( null !== $stored && $stored->metric->slug === $metric->slug && isset( $stored->values[ $field->key ] ) ) {
			return $field->format( $stored->values[ $field->key ] );
		}

		return '';
	}

	/**
	 * Resolves the note to show.
	 *
	 * @param Rejected_Submission|null $rejected A refused submission, if any.
	 * @param Reading|null             $stored   The stored reading, if any.
	 * @param WP_Post                  $post     The post being edited.
	 */
	private function note( ?Rejected_Submission $rejected, ?Reading $stored, WP_Post $post ): string {
		if ( null !== $rejected ) {
			return $rejected->note();
		}

		return null !== $stored ? $stored->note : (string) $post->post_content;
	}
}
