<?php
/**
 * The signed, read-only feed Asyntai reads this site's pages from.
 *
 * Format: Asyntai store feed v1. The product feed is always empty (a Grav
 * site shares pages) and names the pages feed in `store.pages_url`.
 *
 * WHAT IS SENT. Only pages an anonymous visitor can open: published,
 * routable pages with no access rules on them or on a parent, no redirect
 * and no "noindex". The text is the page's own rendered content, without
 * the theme's menus and footer.
 *
 * @package    Grav\Plugin\AsyntaiAiChatbot
 * @copyright  Asyntai
 * @license    MIT
 */

namespace Grav\Plugin\AsyntaiAiChatbot;

use Grav\Common\Grav;

class Feed
{
    /** How far a signed request's clock may drift, in seconds. */
    const CLOCK_SKEW = 300;

    /** Pages per feed page. Each one is rendered. */
    const PAGE_SIZE = 25;

    /** A site with more pages than this is answering wrongly. */
    const MAX_PAGES = 3000;

    /** Header keys that limit who may see a page. */
    const ACCESS_KEYS = array('access', 'login', 'permissions', 'private');

    /**
     * [status, body] for the product feed.
     *
     * @param array $query Query values.
     *
     * @return array
     */
    public static function products(array $query)
    {
        $refused = self::refuse($query);

        if ($refused !== null) {
            return $refused;
        }

        return array(200, array(
            'ok'         => true,
            'store'      => array(
                'name'      => self::siteName(),
                'url'       => State::siteUrl(),
                'currency'  => '',
                'version'   => defined('GRAV_VERSION') ? GRAV_VERSION : '',
                'pages_url' => State::pagesUrl(),
            ),
            'page'       => 1,
            'pages'      => 0,
            'total'      => 0,
            'categories' => array(),
            'products'   => array(),
        ));
    }

    /**
     * [status, body] for the pages feed.
     *
     * @param array $query Query values.
     *
     * @return array
     */
    public static function pages(array $query)
    {
        $refused = self::refuse($query);

        if ($refused !== null) {
            return $refused;
        }

        $page = max(1, (int) (isset($query['page']) ? $query['page'] : 1));
        $all = self::publicPages();
        $slice = array_slice($all, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $items = array();

        foreach ($slice as $item) {
            $record = self::record($item);

            if ($record !== null) {
                $items[] = $record;
            }
        }

        return array(200, array(
            'ok'    => true,
            'page'  => $page,
            'pages' => (int) ceil(count($all) / self::PAGE_SIZE),
            'total' => count($all),
            'items' => $items,
        ));
    }

    /**
     * [403, error] when the request is not a valid signed call, else null.
     * Signed over the raw query values, in a fixed order, before clamping.
     *
     * @param array $query Query values.
     *
     * @return array|null
     */
    private static function refuse(array $query)
    {
        $refused = self::checkSignature($query);

        if ($refused === null && self::maintenance()) {
            // 503, not 403: a 403 tells Asyntai the connection was removed.
            return array(503, array('ok' => false, 'error' => 'The site is in maintenance mode.'));
        }

        return $refused;
    }

    /**
     * @param array $query Query values.
     *
     * @return array|null
     */
    private static function checkSignature(array $query)
    {
        $tokens = State::acceptedTokens();

        if (!$tokens) {
            return array(403, array('ok' => false, 'error' => 'Sharing is switched off on this site.'));
        }

        $ts = isset($query['ts']) ? (string) $query['ts'] : '';

        if ($ts === '' || !ctype_digit($ts)) {
            return array(403, array('ok' => false, 'error' => 'Missing timestamp.'));
        }

        if (abs(time() - (int) $ts) > self::CLOCK_SKEW) {
            return array(403, array('ok' => false, 'error' => 'The request has expired.'));
        }

        $signed = 'page=' . (isset($query['page']) ? (string) $query['page'] : '')
            . '&limit=' . (isset($query['limit']) ? (string) $query['limit'] : '')
            . '&ids=' . (isset($query['ids']) ? (string) $query['ids'] : '')
            . '&ts=' . $ts;
        $sig = strtolower(isset($query['sig']) ? (string) $query['sig'] : '');

        foreach ($tokens as $token) {
            if ($sig !== '' && hash_equals(hash_hmac('sha256', $signed, $token), $sig)) {
                return null;
            }
        }

        return array(403, array('ok' => false, 'error' => 'Bad signature.'));
    }

    // -----------------------------------------------------------------
    // Which pages
    // -----------------------------------------------------------------

    /**
     * Every page an anonymous visitor can open, in route order.
     *
     * @return array
     */
    public static function publicPages()
    {
        $grav = Grav::instance();
        $pages = $grav['pages'];
        $list = array();

        foreach ($pages->all() as $page) {
            if (!$page || !self::isPublic($page)) {
                continue;
            }

            $list[(string) $page->route()] = $page;

            if (count($list) >= self::MAX_PAGES) {
                break;
            }
        }

        ksort($list);

        return array_values($list);
    }

    /**
     * @param object $page A Grav page.
     *
     * @return bool
     */
    private static function isPublic($page)
    {
        if ($page->root() || !$page->isPage() || !$page->published() || !$page->routable()) {
            return false;
        }

        if (method_exists($page, 'isModule') && $page->isModule()) {
            return false;
        }

        $template = (string) $page->template();

        if ($template === 'error' || $template === 'asyntai' || strpos($template, 'search') !== false) {
            return false;
        }

        if ((string) $page->redirect() !== '') {
            return false;
        }

        $header = self::header($page);

        if (self::noindex($header)) {
            return false;
        }

        // Access rules on the page itself or on any parent.
        for ($node = $page; $node && !$node->root(); $node = $node->parent()) {
            $nodeHeader = self::header($node);

            foreach (self::ACCESS_KEYS as $key) {
                if (!empty($nodeHeader[$key])) {
                    return false;
                }
            }

            if (!$node->published()) {
                return false;
            }
        }

        return self::guestMayOpen($page);
    }

    /**
     * A page header as an array. On flex pages header() is a Header object,
     * whose fields an (array) cast does not expose.
     *
     * @param object $page A Grav page.
     *
     * @return array
     */
    private static function header($page)
    {
        $header = $page->header();

        if (is_object($header) && method_exists($header, 'toArray')) {
            $header = $header->toArray();
        } elseif (is_object($header)) {
            $header = get_object_vars($header);
        }

        return is_array($header) ? $header : array();
    }

    /**
     * Ask the login plugin, when present, whether a guest may open the page.
     * This covers rules other than page headers: site-wide login, and other
     * plugins that answer the login plugin's page check.
     *
     * @param object $page A Grav page.
     *
     * @return bool
     */
    private static function guestMayOpen($page)
    {
        $grav = Grav::instance();

        if (!isset($grav['login']) || !method_exists($grav['login'], 'isUserAuthorizedForPage')) {
            return true;
        }

        try {
            $guest = $grav['accounts']->load('');
            $guest->authenticated = false;
            // The login plugin's settings with the page's own login: header
            // on top, as the login plugin merges them itself.
            $settings = (array) $grav['config']->get('plugins.login', array());
            $header = self::header($page);

            if (isset($header['login']) && is_array($header['login'])) {
                $settings = array_replace_recursive($settings, $header['login']);
            }

            $config = new \Grav\Common\Data\Data($settings);

            return (bool) $grav['login']->isUserAuthorizedForPage($guest, $page, $config);
        } catch (\Throwable $e) {
            // When we cannot tell, the page stays out.
            return false;
        }
    }

    /**
     * True while the site is in maintenance mode (the maintenance plugin).
     *
     * @return bool
     */
    public static function maintenance()
    {
        $config = Grav::instance()['config'];

        return (bool) $config->get('plugins.maintenance.enabled', false)
            && (bool) $config->get('plugins.maintenance.active', false);
    }

    /**
     * @param array $header Page header.
     *
     * @return bool
     */
    private static function noindex(array $header)
    {
        $values = array();

        if (isset($header['robots'])) {
            $values[] = $header['robots'];
        }

        if (isset($header['metadata']) && is_array($header['metadata']) && isset($header['metadata']['robots'])) {
            $values[] = $header['metadata']['robots'];
        }

        foreach ($values as $value) {
            if (stripos(is_array($value) ? implode(',', $value) : (string) $value, 'noindex') !== false) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // One record
    // -----------------------------------------------------------------

    /**
     * @param object $page A Grav page.
     *
     * @return array|null
     */
    private static function record($page)
    {
        try {
            $html = (string) $page->content();

            // A modular page keeps its text in its modules.
            foreach ($page->children() as $child) {
                if (method_exists($child, 'isModule') && $child->isModule() && $child->published()) {
                    $html .= "\n" . (string) $child->content();
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        $text = self::text($html);
        $title = trim((string) $page->title());

        if ($text === '' || $title === '') {
            return null;
        }

        $parent = $page->parent();
        $isPost = (string) $page->template() === 'item'
            || ($parent && !$parent->root() && (string) $parent->template() === 'blog');

        $record = array(
            'type'    => $isPost ? 'post' : 'page',
            'title'   => $title,
            'url'     => self::absoluteUrl($page),
            'content' => $text,
        );

        $header = self::header($page);
        $summary = isset($header['metadata']) && is_array($header['metadata']) && isset($header['metadata']['description'])
            ? trim((string) $header['metadata']['description']) : '';

        if ($summary !== '') {
            $record['summary'] = $summary;
        }

        if ($isPost && $parent) {
            $record['blog'] = trim((string) $parent->title());
        }

        return $record;
    }

    /**
     * The page's address on this site's public root.
     *
     * @param object $page A Grav page.
     *
     * @return string
     */
    private static function absoluteUrl($page)
    {
        $grav = Grav::instance();
        $relative = (string) $page->url(false);
        $rootPath = rtrim((string) $grav['uri']->rootUrl(false), '/');

        // url(false) carries the folder Grav runs from; siteUrl() already does.
        if ($rootPath !== '' && strpos($relative, $rootPath) === 0) {
            $relative = substr($relative, strlen($rootPath));
        }

        return State::siteUrl() . ($relative === '' ? '/' : $relative);
    }

    /**
     * Plain text from rendered page HTML, paragraphs kept.
     *
     * @param string $html Rendered content.
     *
     * @return string
     */
    public static function text($html)
    {
        $html = preg_replace('#<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/table|/section|/article|/blockquote|/pre|/dd|/dt)\b[^>]*>#i', "$0\n", $html);
        $html = preg_replace('#<(td|th)\b[^>]*>#i', ' $0', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * @return string
     */
    private static function siteName()
    {
        $title = (string) Grav::instance()['config']->get('site.title', '');

        return $title !== '' ? $title : 'Grav site';
    }
}
