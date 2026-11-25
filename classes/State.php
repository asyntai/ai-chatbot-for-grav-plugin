<?php
/**
 * Everything this plugin remembers, and the calls it makes to Asyntai.
 *
 * Kept in user/data rather than in the plugin's config file: Grav saves a
 * plugin's config from the form it has just rendered, so any key that form
 * does not carry can be lost on the next Save, and the feed token must never
 * be printed into a form in the browser. Version 1.0 kept the site id in the
 * config file; it is moved over the first time it is read.
 *
 * @package    Grav\Plugin\AsyntaiAiChatbot
 * @copyright  Asyntai
 * @license    MIT
 */

namespace Grav\Plugin\AsyntaiAiChatbot;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\HTTP\Response;
use RocketTheme\Toolbox\File\YamlFile;

class State
{
    const FILE = 'user-data://asyntai-ai-chatbot/state.yaml';

    /** Every key a disconnect forgets. */
    const KEYS = array(
        'site_id', 'script_url', 'account_email', 'feed_token', 'feed_enabled',
        'pending_token', 'state', 'state_at',
    );

    const DEFAULT_SCRIPT = 'https://asyntai.com/static/js/chat-widget.js';

    /** @var array|null */
    private static $cache = null;

    /**
     * Where this site's PHP talks to Asyntai. A constant in user/setup.php
     * can point a staging copy at a test server.
     *
     * @return string
     */
    public static function origin()
    {
        if (defined('ASYNTAI_CHATBOT_ORIGIN')) {
            return rtrim(ASYNTAI_CHATBOT_ORIGIN, '/');
        }

        return 'https://asyntai.com';
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    /**
     * @return string
     */
    private static function path()
    {
        return (string) Grav::instance()['locator']->findResource(self::FILE, true, true);
    }

    /**
     * Everything remembered. Read again from disk before a write, so a save
     * never starts from a copy another request has since changed.
     *
     * @param bool $fresh Read from disk even if already read.
     *
     * @return array
     */
    public static function all($fresh = false)
    {
        if (!$fresh && self::$cache !== null) {
            return self::$cache;
        }

        $path = self::path();
        $data = array();

        if ($path !== '' && is_file($path)) {
            try {
                $file = YamlFile::instance($path);
                $file->free();
                $data = $file->content();
                $file->free();
            } catch (\Exception $e) {
                $data = array();
            }
        }

        self::$cache = is_array($data) ? $data : array();
        self::migrate();

        return self::$cache;
    }

    /**
     * Move a 1.0 connection out of the plugin config, once.
     *
     * @return void
     */
    private static function migrate()
    {
        if (isset(self::$cache['site_id']) && self::$cache['site_id'] !== '') {
            return;
        }

        $config = Grav::instance()['config']->get('plugins.asyntai-ai-chatbot');
        $siteId = is_array($config) && isset($config['site_id']) ? trim((string) $config['site_id']) : '';

        if ($siteId === '' || isset(self::$cache['migrated'])) {
            return;
        }

        $data = self::$cache;
        $data['site_id'] = $siteId;
        $data['migrated'] = '1';

        if (!empty($config['account_email'])) {
            $data['account_email'] = (string) $config['account_email'];
        }

        if (!empty($config['script_url'])) {
            $data['script_url'] = (string) $config['script_url'];
        }

        self::write($data);
    }

    /**
     * @param string $name    Key.
     * @param string $default Value when unset.
     *
     * @return string
     */
    public static function get($name, $default = '')
    {
        $data = self::all();

        return array_key_exists($name, $data) ? (string) $data[$name] : $default;
    }

    /**
     * @param string $name  Key.
     * @param string $value Value.
     *
     * @return bool
     */
    public static function set($name, $value)
    {
        $data = self::all(true);
        $data[$name] = (string) $value;

        return self::write($data);
    }

    /**
     * @param string $name Key.
     *
     * @return void
     */
    public static function forget($name)
    {
        $data = self::all(true);

        if (array_key_exists($name, $data)) {
            unset($data[$name]);
            self::write($data);
        }
    }

    /**
     * Forget the connection, the feed token with it.
     *
     * @return void
     */
    public static function forgetAll()
    {
        $data = self::all(true);

        foreach (self::KEYS as $name) {
            unset($data[$name]);
        }

        // Keeps a 1.0 site id in the config file from coming back.
        $data['migrated'] = '1';
        self::write($data);
    }

    /**
     * @param array $data Whole file.
     *
     * @return bool
     */
    private static function write(array $data)
    {
        $path = self::path();

        if ($path === '') {
            return false;
        }

        try {
            Folder::create(dirname($path));
            $file = YamlFile::instance($path);
            $file->save($data);
            $file->free();
        } catch (\Exception $e) {
            return false;
        }

        self::$cache = $data;

        return true;
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /**
     * @return string
     */
    public static function siteId()
    {
        return trim(self::get('site_id', ''));
    }

    /**
     * @return string
     */
    public static function scriptUrl()
    {
        $url = trim(self::get('script_url', ''));

        return preg_match('#^https://[^\s"\'<>]+$#i', $url) ? $url : self::DEFAULT_SCRIPT;
    }

    /**
     * @return bool
     */
    public static function sharingOn()
    {
        return self::get('feed_enabled', '') === '1' && self::get('feed_token', '') !== '';
    }

    /**
     * Tokens the feed accepts right now. The pending one counts while the
     * owner is still signing in, because Asyntai starts reading at sign-in.
     *
     * @return string[]
     */
    public static function acceptedTokens()
    {
        $tokens = array();

        if (self::sharingOn()) {
            $tokens[] = self::get('feed_token');
        }

        $pending = self::get('pending_token', '');

        if ($pending !== '') {
            $tokens[] = $pending;
        }

        return $tokens;
    }

    // -----------------------------------------------------------------
    // Addresses
    // -----------------------------------------------------------------

    /**
     * The public address of this site: system.custom_base_url when the owner
     * set one, else the address of the current request.
     *
     * @return string
     */
    public static function siteUrl()
    {
        $grav = Grav::instance();
        $base = rtrim(trim((string) $grav['config']->get('system.custom_base_url', '')), '/');

        if ($base !== '' && preg_match('#^https?://#i', $base)) {
            return $base;
        }

        return rtrim((string) $grav['uri']->rootUrl(true), '/');
    }

    /**
     * @return string
     */
    public static function feedUrl()
    {
        return self::siteUrl() . '/asyntai-chatbot/feed';
    }

    /**
     * @return string
     */
    public static function pagesUrl()
    {
        return self::siteUrl() . '/asyntai-chatbot/feed/pages';
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /**
     * Body of a GET, or null on any failure.
     *
     * @param string $url     Address.
     * @param int    $timeout Seconds.
     *
     * @return string|null
     */
    public static function httpGet($url, $timeout = 10)
    {
        try {
            $response = Response::request('GET', $url, array(
                'headers'      => array('Accept' => 'application/json', 'User-Agent' => 'Asyntai AI Chatbot (Grav)'),
                'timeout'      => $timeout,
                'max_duration' => $timeout,
            ));

            return self::readBody($response);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Body of a JSON POST, or null on any failure.
     *
     * @param string $url     Address.
     * @param array  $payload Data.
     * @param int    $timeout Seconds.
     *
     * @return string|null
     */
    public static function httpPostJson($url, array $payload, $timeout = 15)
    {
        try {
            $response = Response::request('POST', $url, array(
                'headers'      => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => 'Asyntai AI Chatbot (Grav)',
                ),
                'body'         => json_encode($payload),
                'timeout'      => $timeout,
                'max_duration' => $timeout,
            ));

            return self::readBody($response);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param mixed $response Client response.
     *
     * @return string|null
     */
    private static function readBody($response)
    {
        $code = (int) $response->getStatusCode();

        if ($code < 200 || $code > 299) {
            return null;
        }

        return (string) $response->getContent(false);
    }
}
