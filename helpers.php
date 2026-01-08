<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper functions for Topic Tree GPT plugin.
 * These utilities support content retrieval and JSON processing, 
 * used in both merged (grouped) and unmerged (flat list) modes.
 */

//ANCHOR - Retrieves all published posts of a specified post type, with caching support.
function topic_tree_get_all_content($post_type, $force_refresh = false) {
    $cache_key = 'tt_content_' . $post_type;
    $cached_items = get_transient($cache_key);

    if ($cached_items !== false && !$force_refresh) {
        return $cached_items;
    }

    $items = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if (empty($items)) {
        return [];
    }

    $filtered_items = array_filter($items, function($item) {
        return !empty($item->post_title) && !empty($item->post_date);
    });

    $cloned_items = array_map(function($item) {
        return clone $item;
    }, $filtered_items);

    set_transient($cache_key, $cloned_items, WEEK_IN_SECONDS);
    return $cloned_items;
}

//ANCHOR - Cleans and extracts valid JSON content from a string, returning an empty object if invalid
function topic_tree_clean_json($content) {
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $matches)) {
        $content = $matches[1];
    }

    $content = trim($content);

    if (json_decode($content, true) !== null) {
        return $content;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $json = substr($content, $start, $end - $start + 1);
        if (json_decode($json, true) !== null) {
            return $json;
        }
    }

    return '{}';
}

//ANCHOR - Placeholder function for error logging, currently does nothing
function topic_tree_log_error($message) {
    // Empty function to maintain compatibility if called elsewhere
}