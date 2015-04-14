<?php
/*
Plugin Name: Seamless Schema
Plugin URI: http://techblog.synagila.com/2014/06/02/add-schema-org-support-in-wordpress/
Description: Seamlessly insert schema.org and Open Graph microdata into WordPress.
Version: 1.2
Author: Nicolas Bernier
Author URI: http://www.synagila.com
License: GPL v2

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

	Resources

	http://schema-creator.org/
	http://foolip.org/microdatajs/live/
	http://www.google.com/webmasters/tools/richsnippets

*/

define('SEAMLESS_SCHEMA_BASE', plugin_dir_path(__FILE__));
define('SEAMLESS_SCHEMA_VER', '1.2');
define('SEAMLESS_SCHEMA_URL', plugins_url('/' . basename(dirname(__FILE__))));

// Enable excerpt support for pages
add_post_type_support('page', 'excerpt');

// Include classes
include_once(SEAMLESS_SCHEMA_BASE . 'includes/class.seamless_schema.php');
include_once(SEAMLESS_SCHEMA_BASE . 'includes/class.seamless_schema_metadata.php');
include_once(SEAMLESS_SCHEMA_BASE . 'includes/class.seamless_schema_metabox.php');
include_once(SEAMLESS_SCHEMA_BASE . 'includes/class.seamless_schema_settings.php');


/**
 * Return the Schema metadata object for the current context
 * @return SeamlessSchemaMetadata
 */
function seamless_schema_get_metadata()
{
	global $post, $seamlessSchemaMetadata;

	if (!empty($seamlessSchemaMetadata))
		return $seamlessSchemaMetadata;

	$seamlessSchemaMetadata = new SeamlessSchemaMetadata();

	if (is_front_page())
		$seamlessSchemaMetadata->loadFromSettings('homepage', get_locale());
	else if ((is_single() || is_page() || is_attachment()) && !empty($post))
		$seamlessSchemaMetadata->setPost($post);
	else if (is_author())
		$seamlessSchemaMetadata->loadFromContext('author', get_locale());
	else if (is_category())
		$seamlessSchemaMetadata->loadFromContext('category', get_locale());
	else if (is_tag())
		$seamlessSchemaMetadata->loadFromContext('tag', get_locale());
	else if (is_search())
		$seamlessSchemaMetadata->loadFromContext('search', get_locale());
	else
		$seamlessSchemaMetadata->loadFromSettings('default', get_locale());

	return $seamlessSchemaMetadata;
}

/**
 * Adds the Schema.org attributes in the opening HTML tags
 * Called by wp_head action
 *
 * @param string $buffer
 * @return string
 */
function seamless_schema_add_html_attributes($buffer)
{
	// Get all the opening HTML tags (some themes may have more than a single one)
	if (!preg_match_all('/<html[^>]+>/mi', $buffer, $matches, PREG_SET_ORDER))
		return $buffer;

	foreach($matches as $match)
	{
		$htmlTag = $match[0];

		// Remove existing itemscope and itemtype attributes
		$htmlTag = preg_replace('/[[:space:]]+(itemscope|itemtype)(=[\'"].*?[\'"])?/', '', $htmlTag);

		// Remove tag end
		$htmlTag = preg_replace('/[[:space:]]*>$/', '', $htmlTag);

		// Add the overriden attributes and close tag
		$htmlTag .= ' itemscope="itemscope" itemtype="http://schema.org/' . seamless_schema_get_metadata()->type . '">';

		// Replace HTML tag
		$buffer = str_replace($match[0], $htmlTag, $buffer);
	}

	return $buffer;
}

/**
 * Start output buffering just before HTML rendering
 * Registers the seamless_schema_add_html_attributes callback to add Schema.org attributes
 * in opening HTML tag
 */
function seamless_schema_register_header_buffer_callback()
{
	ob_start("seamless_schema_add_html_attributes");
}
add_action('wp',      'seamless_schema_register_header_buffer_callback');

/**
 * End output buffering during header
 */
function seamless_schema_buffer_end()
{
	@ob_end_flush();
}
add_action('wp_head', 'seamless_schema_buffer_end');

/**
 * Display Schema.org and OpenGraph meta tags in <head>
 * Called by wp_head action
 * @return void
 */
function seamless_schema_head()
{
	$validProperties = SeamlessSchema::getTypeProperties(seamless_schema_get_metadata()->type);

	// Display Schema.org metatags
	foreach(seamless_schema_get_metadata()->data as $property => $value)
		if (!empty($value) && in_array($property, $validProperties))
			echo "\n" . '<meta itemprop="' . esc_attr($property) . '" content="' . esc_attr($value) . '" />';

	// Schema.org to OpenGraph metadata mapping
	$propertyMapping = array(
		'og:title'        => array('name'),
		'og:url'          => array('url'),
		'og:description'  => array('description'),
		'og:image'        => array('image', 'thumbnailUrl'),
		'og:updated_time' => array('dateModified'),
	);

	// Schema.org to OpenGraph content type mapping
	$typeMapping = array(
		'Article'       => 'article',
		'Book'          => 'books.book',
		'LocalBusiness' => 'business.business',
		'Organization'  => 'business.business',
		'Person'        => 'profile',
		'Product'       => 'product',
	);

	if (!empty($typeMapping[seamless_schema_get_metadata()->type]))
		$ogType = $typeMapping[seamless_schema_get_metadata()->type];
	else
		$ogType = 'website';

	// Display OpenGraph metatags
	echo "\n" . '<meta property="og:type" content="' . $ogType . '" />';

	// Facebook admins
	if (get_option('seamless_schema_fb_admins'))
		echo "\n" . '<meta property="fb:admins" content="' . get_option('seamless_schema_fb_admins') . '" />';

	// Facebook application ID
	if (get_option('seamless_schema_fb_app_id'))
		echo "\n" . '<meta property="fb:app_id" content="' . get_option('seamless_schema_fb_app_id') . '" />';

	foreach($propertyMapping as $ogProperty => $schemaProperties)
		foreach($schemaProperties as $schemaProperty)
			if (!empty(seamless_schema_get_metadata()->data[$schemaProperty]))
			{
				echo "\n" . '<meta property="' . $ogProperty . '" content="' . esc_attr(seamless_schema_get_metadata()->data[$schemaProperty]) . '" />';
				break;
			}

	// Display Twitter card metatags

	// Determine card type
	switch(seamless_schema_get_metadata()->type)
	{
		case 'ImageObject':
			$cardType = 'photo';
			break;

		case 'Product':
			$cardType = 'photo';
			break;

		default:
			if (!empty(seamless_schema_get_metadata()->data['image']) || !empty(seamless_schema_get_metadata()->data['image']))
				$cardType = 'summary_large_image';
			else
				$cardType = 'summary';
			break;
	}

	// Display card type
	echo "\n" . '<meta property="twitter:card" content="' . $cardType . '" />';

	// Display site's Twitter username (twitter:site)
	if (get_option('seamless_schema_twitter_site'))
	{
		$siteTwitter = trim(str_replace('@', '', get_option('seamless_schema_twitter_site')));

		if (!empty($siteTwitter))
			echo "\n" . '<meta property="twitter:site" content="@' . esc_html($siteTwitter) . '" />';
	}

	// Display author's Twitter username (twitter:creator)
	if (!empty(seamless_schema_get_metadata()->userTwitter))
	{
		$userTwitter = trim(str_replace('@', '', seamless_schema_get_metadata()->userTwitter));

		if (!empty($userTwitter))
			echo "\n" . '<meta property="twitter:creator" content="@' . esc_html($userTwitter) . '" />';
	}

	// Display Twitter card properties
	$propertyMapping = array(
		'twitter:title'        => array('name'),
		'twitter:description'  => array('description'),
		'twitter:image'        => array('thumbnailUrl', 'image'),
		'twitter:url'          => array('url'),
	);

	foreach($propertyMapping as $twitterProperty => $schemaProperties)
		foreach($schemaProperties as $schemaProperty)
			if (!empty(seamless_schema_get_metadata()->data[$schemaProperty]))
			{
				echo "\n" . '<meta property="' . $twitterProperty . '" content="' . esc_attr(seamless_schema_get_metadata()->data[$schemaProperty]) . '" />';
				break;
			}

	// Retrieving additional OpenGraph and Twitter properties from custom fields
	if (!empty(seamless_schema_get_metadata()->post->ID))
	{
		$metadata = get_metadata('post', seamless_schema_get_metadata()->post->ID);
		foreach($metadata as $dataName => $dataValues)
		{
			if (preg_match('/^(og|twitter)_(.+)$/i', $dataName, $matches))
				foreach ($dataValues as $dataValue)
					echo "\n" . '<meta property="' . strtolower(esc_attr($matches[1])) . ':' . strtolower(esc_attr($matches[2])) . '" content="' . esc_attr($dataValue) . '" />';
		}
	}

	echo "\n";
}
add_action('wp_head', 'seamless_schema_head');

/**
 * Enqueue scripts and CSS in admin
 * Called by admin_enqueue_scripts action
 * @return void
 */
function seamless_schema_admin_enqueue_scripts()
{
	wp_enqueue_style('seamless_schema_admin', SEAMLESS_SCHEMA_URL .'/css/admin.css', array(), SEAMLESS_SCHEMA_VER);
}
add_action('admin_enqueue_scripts', 'seamless_schema_admin_enqueue_scripts');

/**
 * Init plugin translations
 * Called by admin_enqueue_scripts action
 * @return void
 */
function seamless_schema_init()
{
	load_plugin_textdomain('seamless-schema', false, basename(dirname(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'seamless_schema_init');

/**
 * Add profile fields to users
 * @param array $profile_fields
 * @return array
 */
function seamless_schema_modify_contact_methods($profile_fields)
{
	// Add Twitter username for site cards
	if (empty($profile_fields['twitter']))
		$profile_fields['twitter'] = __("Twitter username", 'seamless-schema');

	return $profile_fields;
}
add_filter('user_contactmethods', 'seamless_schema_modify_contact_methods');