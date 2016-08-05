<?php
/**
 * Plugin Name: GeoSearch
 * Plugin URI: http://www.commercehouse.com
 * Description: Provides an API endpoint for searching of custom post types by geolocation post meta values.
 * Version: 1.0.0
 * Author: Alex Brombal
 * License: Private
 */


// Set this to true when in development mode
define('DEV', true);



// Include ACF if it doesn't exist already

if (!function_exists('acf'))
{
    // Hide menu item for ACF if using the embedded version.
    if (!DEV) add_filter('acf/settings/show_admin', '__return_false');

    include_once __DIR__ . '/acf/acf.php';
}



// Create indexed search table when plugin is activated

register_activation_hook(__FILE__, function() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$wpdb->prefix}geosearch (
		post_id bigint(20) NOT NULL,
		field varchar(255) NOT NULL,
		position geometry NOT NULL,
		SPATIAL INDEX(position),
		UNIQUE(`post_id`, `field`(20))
	) {$wpdb->get_charset_collate()} Engine=MyISAM;");
});


// Delete search table when plugin is deactivated

register_deactivation_hook(__FILE__, function() {
    global $wpdb;
    $wpdb->query("DROP TABLE {$wpdb->prefix}geosearch");
});



// When a post is saved, add entries to the search table if necessary

add_action('init', function() {

    $options = get_fields('options');

    if (empty($options['geosearch_fields']))
        return;

    foreach ($options['geosearch_fields'] as $search)
    {
        $update_meta_cb = function($mid, $object_id, $meta_key, $meta_value) use ($search) {
            global $wpdb;
            if ($search['post_type'] != get_post_type($object_id) || $search['fieldname'] != $meta_key)
                return;
            $wpdb->query($sql = $wpdb->prepare("REPLACE INTO {$wpdb->prefix}geosearch VALUES (%d, %s, Point(%d, %d))", $object_id, $search['fieldname'], $meta_value['lat'], $meta_value['lng']));
        };
        add_action("added_post_meta", $update_meta_cb, 10, 4);
        add_action("added_post_meta", $update_meta_cb, 10, 4);

        $delete_meta_cb = function($mid, $object_id, $meta_key, $meta_value) use ($search) {
            global $wpdb;
            if ($search['post_type'] != get_post_type($object_id) || $search['fieldname'] != $meta_key)
                return;
            $wpdb->query($sql = $wpdb->prepare("DELETE FROM {$wpdb->prefix}geosearch WHERE post_id = %s AND field = %s", $object_id, $search['fieldname']));
        };
        add_action("deleted_{$search['post_type']}_meta", $delete_meta_cb, 10, 4);
    }
});




// Add an endpoint that generates a set of random post entries.
// TODO: delete this when done

add_action('init', function() {
    add_rewrite_rule('^geosearch/generate', 'index.php?geosearch_generate=1', 'top');
});

add_action('parse_request', function() {
    global $wp;
    if (!isset($wp->query_vars['geosearch_generate']))
        return;

    $generated = new WP_Query([ 'post_type' => [ 'page', 'post' ], 'nopaging' => true, 'post_status' => 'any' ]);
    foreach ($generated->get_posts() as $post)
        wp_delete_post($post->ID, true);

    for ($i = 1; $i <= 1000; $i++)
    {
        $id = wp_insert_post([
            'post_title' => 'Test post ' . $i,
            'post_status' => 'publish'
        ]);
        $l = acf_get_field('location');
        update_field($l['key'], [ 'address' => '', 'lat' => (rand(-90000, 90000) / 1000), 'lng' => (rand(-180000, 180000) / 1000) ], $id);
    }

    for ($i = 1; $i <= 1000; $i++)
    {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_title' => 'Test page ' . $i,
            'post_status' => 'publish'
        ]);
        $l = acf_get_field('pagelocation');
        update_field($l['key'], [ 'address' => '', 'lat' => (rand(-90000, 90000) / 1000), 'lng' => (rand(-180000, 180000) / 1000) ], $id);
    }

    echo 'done!';
    exit;
});



// Reindex the geosearch data

add_action('wp_ajax_geosearch-reindex', function() {
    global $wpdb;

    ini_set('display_errors', 1);
    error_reporting(E_ALL);

    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}geosearch");

    $options = get_fields('options');

    if (empty($options['geosearch_fields']))
        return;

    foreach ($options['geosearch_fields'] as $search)
    {
        $wpdb->query($sql = $wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.id = pm.post_id WHERE pm.meta_key = %s", $search['fieldname']));

        foreach ($wpdb->last_result as $result)
        {
            $loc = @unserialize($result->meta_value);
            $wpdb->insert("{$wpdb->prefix}geosearch", [ 'post_id' => $result->post_id, 'field' => $search['fieldname'], 'location' => "Point({$loc['lat']} {$loc['lng']})" ]);
        }
    }


});



// SELECT post_id, AsText(position) FROM `wp_geosearch` WHERE MBRContains(GeomFromText('Polygon((-15 -15, -15 25, 25 25, 25 -15, -15 -15))'), position)
/*
 $lat = $_GET['lat'];
 $lon = $_GET['lon'];
 $radius $_GET['radius'];
 $radiusMaxDegree = $radius / 69; // The maximum degrees that the radius miles could possibly be. This helps limit the results using the spatial index, before we use the haversine formula.
 $box = [ $lon - $radiusMaxDegree, $lat - $radiusMaxDegree, $lon + $radiusMaxDegree, $lat + $radiusMaxDegree ];

 SELECT *
 FROM wp_posts
 INNER JOIN (
   SELECT *,
    (
      3959 * acos (
        cos ( radians( 0 ) )
        * cos( radians( X(position) ) )
        * cos( radians( Y(position) ) - radians( 0 ) )
        + sin ( radians( 0 ) )
        * sin( radians( X(position) ) )
      )
    ) AS distance
   FROM `wp_geosearch`
   WHERE MBRContains(GeomFromText('Polygon(({$box[0]} {$box[1]}, {$box[0]} {$box[3]}, {$box[2]} {$box[1]}, {$box[2]} {$box[3]}, {$box[0]} {$box[1]}))'), position)
 ) distances
   ON wp_posts.id = distances.post_id
WHERE distance < $distance

 SELECT
    *,
    X(position),
    Y(position),
    (
      3959 * acos (
        cos ( radians( $lat ) )
        * cos( radians( X(position) ) )
        * cos( radians( Y(position) ) - radians( $lon ) )
        + sin ( radians( $lat ) )
        * sin( radians( X(position) ) )
      )
	) AS distance
  FROM (
    SELECT * FROM `wp_geosearch`
    WHERE MBRContains(GeomFromText('Polygon(({$box[0]} {$box[1]}, {$box[0]} {$box[3]}, {$box[2]} {$box[1]}, {$box[2]} {$box[3]}, {$box[0]} {$box[1]}))'), position)
  )
  WHERE distance < $distance
 */

if (!DEV)
    acf_add_local_field_group(json_decode(file_get_contents(__DIR__ . '/acf.json'), true));

acf_add_options_sub_page([
    /* (string) The title displayed on the options page. Required. */
    'page_title' => 'GeoSearch Settings',

    /* (string) The title displayed in the wp-admin sidebar. Defaults to page_title */
    'menu_title' => 'GeoSearch',

    /* (string) The slug name to refer to this menu by (should be unique for this menu).
    Defaults to a url friendly version of menu_slug */
    'menu_slug' => 'geosearch',

    /* (string) The capability required for this menu to be displayed to the user. Defaults to edit_posts.
    Read more about capability here: http://codex.wordpress.org/Roles_and_Capabilities */
    'capability' => 'edit_theme_options',

    /* (string) The slug of another WP admin page. if set, this will become a child page. */
    'parent_slug' => 'options-general.php',

    /* (boolean)  Whether to load the option (values saved from this options page) when WordPress starts up.
    Defaults to false. Added in v5.2.8. */
    // 'autoload' => false,
]);

add_filter('query_vars', function($vars) {
    $vars[] = 'geo_radius';
    $vars[] = 'geo_bounds';
    $vars[] = 'geosearch_generate';
    return $vars;
});

add_filter('posts_join', function($join, $query) {
    if (empty($query->query_vars['geo_radius']) && empty($query->query_vars['geo_bounds']))
        return $join;

    global $wpdb;

    if (!empty($query->query_vars['geo_bounds']))
    {
        $join .= "INNER JOIN {$wpdb->prefix}geosearch geosearch ON {$wpdb->posts}.id = geosearch.post_id";
    }

    else if (!empty($query->query_vars['geo_radius']))
    {
        $parts = explode(',', $query->query_vars['geo_radius']);
        $lat = $parts[1];
        $lon = $parts[2];
        $radius = $parts[3];
        $radiusMaxDegree = $radius / 69; // The maximum degrees that the radius miles could possibly be. This helps limit the results using the spatial index, before we use the haversine formula.
        $box = [ $lon - $radiusMaxDegree, $lat - $radiusMaxDegree, $lon + $radiusMaxDegree, $lat + $radiusMaxDegree ];

        $join .= $wpdb->prepare("INNER JOIN (
           SELECT *,
            (
                3959 * acos (
                cos ( radians( %d ) )
                * cos( radians( X(position) ) )
                * cos( radians( Y(position) ) - radians( %d ) )
                + sin ( radians( %d ) )
                * sin( radians( X(position) ) )
                )
            ) AS distance
            FROM `wp_geosearch`
            WHERE MBRContains(GeomFromText('Polygon((%d %d, %d %d, %d %d, %d %d, %d %d))'), position)
        ) geosearch
            ON wp_posts.id = geosearch.post_id",
            $lat,
            $lon,
            $lat,
            $box[0], $box[1],
            $box[0], $box[3],
            $box[2], $box[1],
            $box[2], $box[3],
            $box[0], $box[1]
        );
    }

    return $join;
}, 10, 2);

add_filter('posts_where', function($where, $query) {
    if (empty($query->query_vars['geo_radius']) && empty($query->query_vars['geo_bounds']))
        return $where;

    global $wpdb;

    if (!empty($query->query_vars['geo_bounds']))
    {
        $box = explode(',', $query->query_vars['geo_bounds']);
        $where .= $wpdb->prepare("AND geosearch.field = %s AND MBRContains(GeomFromText('Polygon((%d %d, %d %d, %d %d, %d %d, %d %d))'), geosearch.position)",
            $box[0],
            $box[1], $box[2],
            $box[1], $box[4],
            $box[3], $box[2],
            $box[3], $box[4],
            $box[1], $box[2]
        );
    }

    else if (!empty($query->query_vars['geo_radius']))
    {
        $parts = explode(',', $query->query_vars['geo_radius']);
        $radius = $parts[3];

        $where .= $wpdb->prepare("AND geosearch.field = %s AND geosearch.distance < %d", $parts[0], $radius);
        //$lat = $_GET['lat'];
        //$lon = $_GET['lon'];
        //$radius = $_GET['radius'];
        //$radiusMaxDegree = $radius / 69; // The maximum degrees that the radius miles could possibly be. This helps limit the results using the spatial index, before we use the haversine formula.
        //$box = [ $lon - $radiusMaxDegree, $lat - $radiusMaxDegree, $lon + $radiusMaxDegree, $lat + $radiusMaxDegree ];

    }

    return $where;
}, 10, 2);

add_filter('the_posts', function($posts, $query) {
    if (empty($query->query_vars['geo_radius']) && empty($query->query_vars['geo_bounds']))
        return $posts;

    foreach ($posts as $post)
    {
        $post->geosearch = [
            'distance' => 5
        ];
    }

    return $posts;
}, 10, 2);


add_action('json_api_import_wp_post', function($json, $post) {

}, 10, 2);