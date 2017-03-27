<?php
/*
Plugin Name: Docker Creator
Plugin URI:  https://developer.wordpress.org/plugins/the-basics/
Description: Creates Dockerfiles
Version:     0.0.1
Author:      withinboredom
Author URI:  https://www.withinboredom.info/
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: docker-creator
Domain Path: /languages
*/

defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

require_once( 'vendor/autoload.php' );

use Docker\Docker;
use Docker\DockerClient;

function BaseDockerFile() {
	$wpVersion = getenv( 'WORDPRESS_VERSION' );

	return [
		'contents' => "
FROM withinboredom/scalable-wordpress:$wpVersion-apache
"
	];
}

/*
Expects json of the form:
{
	"plugins": [
		{
			"type": "wporg",
			"slug": "jetpack",
			"version": "latest"
		},
		{
			"type": "url",
			"url": "http://url/to/plugin.zip"
		}
	],
	"theme": {
		"type": "wporg",
		"slug": "twentysixteen",
		"version": "latest"
	}
}
*/

function getDockerSlugUrl( $plugin ) {
	$url = null;
	switch ( $plugin['type'] ) {
		case 'wporg':
			$slug     = $plugin['slug'] ?: die( 'invalid slug' );
			$version  = $plugin['version'] ?: 'latest';
			$response = wp_remote_get( "https://api.wordpress.org/plugins/info/1.0/$slug.json" );
			$response = json_decode( $response['body'] );

			if ( $response === null ) {
				die( 'invalid slug' );
			}

			$url = $response->download_link;

			if ( $version !== 'latest' ) {
				$url = explode( '.', $url )[0] . ".$version" . '.zip';
			}

			break;
		case 'url':
			$url = $plugin['url'];
			break;
	}

	return $url;
}

/**
 * @param WP_REST_Request $params
 *
 * @return array
 */

function ExtendDockerFile( WP_REST_Request $params ) {
	$plugins = $params->get_param( 'plugins' );
	$theme   = $params->get_param( 'themes' );

	$base = BaseDockerFile()['contents'];

	$base .= "USER www-data\n";

	foreach ( $plugins as $plugin ) {
		$url = getDockerSlugUrl( $plugin );

		if ( empty( $url ) ) {
			continue;
		}

		$base .= "RUN curl $url > /var/www/html/wp-content/plugins/$(basename $url) && cd /var/www/html/wp-content/plugins && unzip $(basename $url)\n";
	}
	unset( $plugin );

	$themeUrl = getDockerSlugUrl( $theme );
	if ( ! empty( $themeUrl ) ) {
		$base .= "RUN curl $themeUrl > /var/www/html/wp-content/themes/$(basename $themeUrl) && cd /var/www/html/wp-content/themes && unzip \n";
	}

	$base .= "USER root\n";

	return [
		"contents" => $base
	];
}

function BuildDockerWP( WP_REST_Request $params ) {
	$key = wp_generate_password( 12, false, false );
	set_transient( 'docker_' . $key, $params, DAY_IN_SECONDS );
	//update_option( 'docker_' . $key, serialize( $params ) );
	$client = new DockerClient( [
		'remote_socket' => 'unix:///var/run/docker.sock'
	] );

	$docker = new Docker( $client );

	$build = [
		't'      => 'withinboredom/scalable-wordpress:' . $key,
		'remote' => 'http://localhost/wp-json/docker/v1/Dockerfile?key=' . $key,
		'pull'   => true
	];

	$tarball = __DIR__ . '/empty.tar';
	var_dump( $tarball );
	//$tarball = file_get_contents( $tarball );

	try {
		$results = $docker->getImageManager()->build( $tarball, $build );
		$output  = [];
		foreach ( $results as $result ) {
			$output[] = $result->getStream();
		}

		return $output;
	} catch ( Exception $exception ) {
		return [
			$exception->getMessage(),
			$exception->getTrace()
		];
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'docker/v1', 'file', [
		'methods'  => 'GET',
		'callback' => 'BaseDockerFile'
	] );
	register_rest_route( 'docker/v1', 'file', [
		'methods'  => 'POST',
		'callback' => 'ExtendDockerFile'
	] );
	register_rest_route( 'docker/v1', 'Dockerfile', [
		'methods'  => 'GET',
		'callback' => function ( $request ) {
			//$request = unserialize( get_option( 'docker_' . $request->get_param( 'key' ) ) );
			$request = get_transient( 'docker_' . $request->get_param( 'key' ) );

			$contents = ExtendDockerFile( $request )['contents'];

			update_option( 'docker_' . $request->get_param( 'key' ), null );

			return $contents;
		}
	] );
	register_rest_route( 'docker/v1', 'build', [
		'methods'  => 'POST',
		'callback' => 'BuildDockerWP'
	] );
} );