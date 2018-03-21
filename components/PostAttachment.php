<?php

namespace jri\components;

use jri\models\RwdSet;
use jri\models\ImageSize;

/**
 * Patch standard <img> "srcset" attribute generation
 */
class PostAttachment {

	/**
	 * Internal cache for advanced post thumbnails srcset feature
	 *
	 * @var string $calculated_image_size
	 */
	private $calculated_image_size;

	/**
	 * Class constructor.
	 * initialize WordPress hooks
	 */
	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'after_theme_setup' ) );

		// load rwd template functions.
		include( JRI_ROOT . '/just-rwd-functions.php' );
		add_action( 'wp_footer', 'rwd_print_styles' );
	}

	/**
	 * Add hooks which patch wordpress <img> srcset and sizes attributes.
	 */
	public function add_image_responsive_hooks() {
                add_filter( 'the_content', array( $this, 'make_content_images_responsive' ) );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'set_calculated_image_size_cache' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'calculate_image_srcset' ), 10, 5 );
		add_filter( 'wp_calculate_image_sizes', array( $this, 'calculate_image_sizes' ), 10, 5 );
		add_filter( 'jio_settings_image_sizes', array( $this, 'add_jio_image_sizes' ) );
	}

	/**
	 * Check custom values on after theme setup hook.
	 *
	 * If current theme has configuration for this plugin - we init hooks required and parse image settings.
	 *
	 * @return void;
	 */
	public function after_theme_setup() {

		$settings = apply_filters( 'rwd_image_sizes', array() );
		if ( empty( $settings ) ) {
			// theme or plugin doesn't add any filters and we don't have any.
			return;
		}

		$rwd_defaults = include JRI_ROOT . '/data/rwd-sizes.php';
		$settings     = array_merge( $rwd_defaults, $settings );

		global $rwd_image_sizes, $rwd_image_options;
		$rwd_image_sizes = $rwd_image_options = array();

		foreach ( $settings as $key => $params ) {
			$rwd_image_sizes[ $key ] = new RwdSet( $key, $params );
		}

		add_theme_support( 'post-thumbnails' );
		$this->add_image_responsive_hooks();
	}

	/**
	 * Set image size
	 *
	 * @param string  $image image source.
	 * @param int     $attachment_id media attachment ID.
	 * @param mixed   $size size details.
	 * @param boolean $icon not used.
	 *
	 * @return string
	 */
	public function set_calculated_image_size_cache( $image, $attachment_id, $size, $icon ) {
		$this->calculated_image_size = $size;

		return $image;
	}

        /**
        * Filters 'img' elements in post content to add 'srcset' and 'sizes' attributes.
        *
        * @since 4.4.0
        *
        * @see wp_image_add_srcset_and_sizes()
        *
        * @param string $content The raw post content to be filtered.
        * @return string Converted content with 'srcset' and 'sizes' attributes added to images.
        */
        public function make_content_images_responsive( $content ) {
            if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) ) {
		return $content;
            }

            $selected_images = $attachment_ids = array();

            foreach( $matches[0] as $image ) {
                    if ( false === strpos( $image, ' srcset=' ) && preg_match( '/wp-image-([0-9]+)/i', $image, $class_id ) &&
                            ( $attachment_id = absint( $class_id[1] ) ) ) {

                            /*
                             * If exactly the same image tag is used more than once, overwrite it.
                             * All identical tags will be replaced later with 'str_replace()'.
                             */
                            $selected_images[ $image ] = $attachment_id;
                            // Overwrite the ID when the same image is included more than once.
                            $attachment_ids[ $attachment_id ] = true;
                    }
            }

            if ( count( $attachment_ids ) > 1 ) {
                    /*
                     * Warm the object cache with post and meta information for all found
                     * images to avoid making individual database calls.
                     */
                    _prime_post_caches( array_keys( $attachment_ids ), false, true );
            }

            foreach ( $selected_images as $image => $attachment_id ) {
                if( preg_match( '/size-([a-z]+)/i', $image, $size ) && has_image_size( $size[1] ) ) {
                    $content = str_replace( $image, get_rwd_attachment_image( $attachment_id, $size[1], 'img' ), $content );
                } else { 
                    $image_meta = wp_get_attachment_metadata( $attachment_id );
                    $content = str_replace( $image, wp_image_add_srcset_and_sizes( $image, $image_meta, $attachment_id ), $content );  
                }
            }

            return $content;
        }

	/**
	 * Calculate image sizes for srcset
	 *
	 * @param array  $sources Image file pathes grouped by image width dimention.
	 * @param array  $size_array Image widthes, which are lower than image, which should be displayed.
	 * @param string $image_src Image src of resized image of "last_image_size_called" size.
	 * @param array  $image_meta Image information with final dimension for each registered image size.
	 * @param int    $attachment Attachment ID.
	 *
	 * @return array
	 */
	public function calculate_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment ) {
		/* @var $rwd_image_sizes RwdSet[] */
		global $rwd_image_sizes;
		if ( empty( $this->calculated_image_size )
		     || is_array( $this->calculated_image_size )
		     || ! array_key_exists( $this->calculated_image_size, $image_meta['sizes'] )
		     || empty( $rwd_image_sizes[ $this->calculated_image_size ]->options )
		) {
			return $sources;
		}

		$rwd_set     = $rwd_image_sizes[ $this->calculated_image_size ];
		$rwd_sources = array();

		if ( ! isset( $image_meta['sizes'][ $rwd_set->key ] ) ) {
			return $sources;
		}

		$set_image_width = $image_meta['sizes'][ $rwd_set->key ]['width'];
		foreach ( $rwd_set->options as $rwd_option ) {
			// check that we have image resized in required image size.
			if ( ! isset( $image_meta['sizes'][ $rwd_option->key ] ) ) {
				continue;
			}

			$option_image_width = $image_meta['sizes'][ $rwd_option->key ]['width'];
			// Check that option width is lower than main image source and option image really exists.
			if ( $option_image_width > $set_image_width || ! isset( $sources[ $option_image_width ] ) ) {
				continue;
			}

			$rwd_sources[ $option_image_width ] = array(
				'url'        => $sources[ $option_image_width ]['url'],
				'value'      => strtr( $rwd_option->srcset, array( '{w}' => $option_image_width ) ),
				'descriptor' => '',
			);
		}

		return $rwd_sources;
	}

	/**
	 * Calculate image sizes
	 *
	 * @param array  $sizes Img sizes attribute, generated by WP.
	 * @param mixed  $size_array Some width and height, not sure how it's used.
	 * @param string $image_src Resized image original source.
	 * @param array  $image_meta Image information with final dimension for each registered image size.
	 * @param int    $attachment_id Attachment ID.
	 *
	 * @return array
	 */
	public function calculate_image_sizes( $sizes, $size_array, $image_src, $image_meta, $attachment_id ) {
		/* @var $rwd_image_sizes RwdSet[] */
		global $rwd_image_sizes;
		if ( empty( $this->calculated_image_size )
		     || is_array( $this->calculated_image_size )
		     || empty( $rwd_image_sizes[ $this->calculated_image_size ]->options )
		) {
			return $sizes;
		}

		$rwd_set   = $rwd_image_sizes[ $this->calculated_image_size ];
		$rwd_sizes = array();

		if ( ! isset( $image_meta['sizes'][ $rwd_set->key ] ) ) {
			return $sizes;
		}

		$set_image_width = $image_meta['sizes'][ $rwd_set->key ]['width'];
		foreach ( $rwd_set->options as $rwd_option ) {
			// check that we have image resized in required image size.
			if ( ! isset( $image_meta['sizes'][ $rwd_option->key ] ) ) {
				continue;
			}

			$option_image_width = $image_meta['sizes'][ $rwd_option->key ]['width'];
			// Check that option width is lower than main image source and option image really exists.
			if ( $option_image_width > $set_image_width || empty( $rwd_option->sizes ) ) {
				continue;
			}

			$rwd_sizes[ $option_image_width ] = strtr( $rwd_option->sizes, array( '{w}' => $option_image_width ) );
		}

		$rwd_sizes = implode( ', ', $rwd_sizes );

		return $rwd_sizes;
	}

	/**
	 * Clean up <img> "src" attribute at all if we generate correct srcset and sizes attributes.
	 *
	 * @param array        $attr Attributes for the image markup.
	 * @param WP_Post      $attachment Image attachment post.
	 * @param string|array $size Requested size. Image size or array of width and height values
	 *                                 (in that order). Default 'thumbnail'.
	 *
	 * @return mixed
	 */
	public function attachment_image_attributes( $attr, $attachment, $size ) {
		global $rwd_image_sizes;
		if ( empty( $size )
		     || is_array( $size )
		     || empty( $rwd_image_sizes[ $size ] )
		) {
			return $attr;
		}

		// remove src attribute at all, because we have the best srcset/sizes attributes.
		if ( isset( $attr['src'] ) ) {
			unset( $attr['src'] );
		}

		return $attr;
	}

	/**
	 * WordPress by default add keys like thumbnail, medium, etc.
	 * To speed up loop of regeneration we need to remove duplicated keys.
	 *
	 * @param array $image_sizes  Image size names array.
	 *
	 * @return mixed
	 */
	public function add_jio_image_sizes( $image_sizes ) {
		global $rwd_image_options;
		foreach ( $rwd_image_options as $subkey => $option ) {

			$image_sizes[ $subkey ] = array(
				'width'  => $option->size->w,
				'height' => $option->size->h,
				'crop'   => $option->size->crop,
			);

			if ( $option->retina_options ) {
				foreach ( $option->retina_options as $retina_descriptor => $multiplier ) {
					$retina_key = ImageSize::get_retina_key( $option->key, $retina_descriptor );

					$image_sizes[ $retina_key ] = array(
						'width'  => $option->size->w * $multiplier,
						'height' => $option->size->h * $multiplier,
						'crop'   => $option->size->crop,
					);
				}
			}
		}

		return $image_sizes;
	}

}
