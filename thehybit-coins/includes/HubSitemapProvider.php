<?php
/**
 * The hub's pillars and articles in WordPress's core sitemaps (2.16.0):
 * /wp-sitemap-thbhub-1.xml. Loaded only when core sitemaps exist.
 *
 * @package TheHybit\Coins
 */

namespace TheHybit\Coins;

defined('ABSPATH') || exit;

final class HubSitemapProvider extends \WP_Sitemaps_Provider
{
    public function __construct(private Hub $hub)
    {
        $this->name = 'thbhub';
        $this->object_type = 'thbhub';
    }

    public function get_url_list($page_num, $object_subtype = '')
    {
        return array_map(static fn($e) => array_filter(['loc' => $e['loc'], 'lastmod' => $e['lastmod']]),
            array_slice($this->hub->sitemapEntries(), ($page_num - 1) * 2000, 2000));
    }

    public function get_max_num_pages($object_subtype = '')
    {
        return max(1, (int) ceil(count($this->hub->sitemapEntries()) / 2000));
    }
}
