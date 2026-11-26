<?php
/**
 * The connect handshake and the settings actions, shared by both admins.
 *
 * The classic admin calls these through this plugin's own admin endpoint,
 * Admin2 (Grav 2) through an API route. Both check the user and the request
 * before they get here; this class only does the work.
 *
 * The handshake, in order:
 *   1. "Get started" calls prepare(). With page sharing ticked, PHP makes a
 *      feed token and parks it at Asyntai under a one-time handshake id.
 *   2. The browser opens the Asyntai sign-in window with that id.
 *   3. PHP polls Asyntai for the id. Once the owner has signed in, the answer
 *      carries their site id, and finish() stores it.
 *
 * Nothing is ever loaded from Asyntai as a script inside the administration.
 *
 * @package    Grav\Plugin\AsyntaiAiChatbot
 * @copyright  Asyntai
 * @license    MIT
 */

namespace Grav\Plugin\AsyntaiAiChatbot;

use Grav\Common\Grav;

class Connect
{
    /** How long a handshake stays valid, in seconds. */
    const STATE_TTL = State::STATE_TTL;

    /**
     * Run one task. Throws \RuntimeException with an HTTP status as its code.
     *
     * @param string $task    Task name.
     * @param array  $payload Request body.
     * @param string $email   The signed-in administrator's email.
     *
     * @return array
     */
    public static function run($task, array $payload, $email)
    {
        switch ($task) {
            case 'status':
                return self::status();

            case 'prepare':
                return self::prepare(!empty($payload['share']), $email);

            case 'poll':
                return self::poll(isset($payload['state']) ? (string) $payload['state'] : '');

            case 'finish':
                return self::finish($payload);

            case 'share':
                return self::share(!empty($payload['enabled']));

            case 'sync':
                return self::sync();

            case 'disconnect':
                State::forgetAll();

                return array('ok' => true);
        }

        throw new \RuntimeException('Unknown task', 400);
    }

    /**
     * What the settings screen shows.
     *
     * @return array
     */
    public static function status()
    {
        return array(
            'connected'     => State::siteId() !== '',
            'account_email' => State::get('account_email', ''),
            'sharing'       => State::sharingOn(),
            'has_token'     => State::get('feed_token', '') !== '',
        );
    }

    /**
     * @param bool   $share Share published pages with the chatbot.
     * @param string $email Administrator email, to prefill the sign-in.
     *
     * @return array
     */
    private static function prepare($share, $email)
    {
        $state = 'grav_' . bin2hex(random_bytes(12));

        if ($share) {
            $token = bin2hex(random_bytes(24));
            $body = State::httpPostJson(State::origin() . '/api/v1/wp-search/stage/', array(
                'state'         => $state,
                'platform'      => 'grav',
                'feed_protocol' => 'store_feed_v1',
                // A chatbot install never unlocks the AI Search bar.
                'product'       => 'chat',
                'site_url'      => State::siteUrl(),
                'feed_url'      => State::feedUrl(),
                'feed_token'    => $token,
            ));

            if ($body === null) {
                throw new \RuntimeException('Could not reach Asyntai. Check that this site can make outgoing HTTPS requests.', 502);
            }

            State::set('pending_token', $token);
            State::set('pending_at', (string) time());
        } else {
            State::forget('pending_token');
            State::forget('pending_at');
        }

        foreach (array('polled_site_id', 'polled_email', 'polled_script') as $key) {
            State::forget($key);
        }

        State::set('state', $state);
        State::set('state_at', (string) time());

        $url = State::origin() . '/wp-auth?' . http_build_query(array(
            'state'    => $state,
            'platform' => 'grav',
            'product'  => 'chat',
            'site_url' => State::siteUrl(),
            'wp_email' => (string) $email,
            'lang'     => self::language(),
        ));

        return array('state' => $state, 'url' => $url);
    }

    /**
     * @param string $state The handshake id this site made.
     *
     * @return array
     */
    private static function poll($state)
    {
        $state = trim($state);

        // Only the handshake this site just started is ever polled.
        if ($state === '' || $state !== State::get('state', '')) {
            throw new \RuntimeException('Unknown handshake', 400);
        }

        if (time() - (int) State::get('state_at', '0') > self::STATE_TTL) {
            throw new \RuntimeException('This connection attempt has expired. Please press Get started again.', 408);
        }

        $body = State::httpGet(State::origin() . '/api/v1/wp-search/connect-status/?state=' . rawurlencode($state));
        $decoded = $body === null ? null : json_decode($body, true);

        if (!is_array($decoded) || empty($decoded['ready']) || empty($decoded['data']['site_id'])) {
            return array('ready' => false);
        }

        $data = $decoded['data'];
        $siteId = trim((string) $data['site_id']);

        if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $siteId)) {
            return array('ready' => false);
        }

        // Kept here, so finish() stores what PHP received, never what the
        // browser sends back.
        State::set('polled_site_id', $siteId);
        State::set('polled_email', isset($data['account_email']) ? (string) $data['account_email'] : '');
        State::set('polled_script', isset($data['script_url']) ? (string) $data['script_url'] : '');

        return array('ready' => true);
    }

    /**
     * Store the sign-in result. A parked feed token becomes the live one.
     *
     * @param array $payload Request body.
     *
     * @return array
     */
    private static function finish(array $payload)
    {
        // Only the result PHP itself got from polling Asyntai is stored.
        $siteId = State::get('polled_site_id', '');

        if (State::get('state', '') === '' || $siteId === '') {
            throw new \RuntimeException('Unknown handshake', 400);
        }

        State::set('site_id', $siteId);

        $scriptUrl = trim(State::get('polled_script', ''));

        if (State::isOurScript($scriptUrl)) {
            State::set('script_url', $scriptUrl);
        } else {
            State::forget('script_url');
        }

        $email = trim(State::get('polled_email', ''));
        State::set('account_email', function_exists('mb_substr') ? mb_substr($email, 0, 255) : substr($email, 0, 255));

        $pending = State::get('pending_token', '');
        $pendingAt = (int) State::get('pending_at', '0');

        if ($pending !== '' && time() - $pendingAt <= self::STATE_TTL) {
            State::set('feed_token', $pending);
            State::set('feed_enabled', '1');
        }

        foreach (array('pending_token', 'pending_at', 'state', 'state_at', 'polled_site_id', 'polled_email', 'polled_script') as $key) {
            State::forget($key);
        }

        return array('ok' => true);
    }

    /**
     * Switch page sharing on or off.
     *
     * @param bool $on New value.
     *
     * @return array
     */
    private static function share($on)
    {
        if ($on && State::get('feed_token', '') === '') {
            // Connected before sharing existed, or without it: Asyntai has no
            // token for this site yet, so the owner signs in once more.
            return array('ok' => false, 'needs_connect' => true);
        }

        State::set('feed_enabled', $on ? '1' : '0');

        if (!$on) {
            // Off means off: no key from an earlier sign-in keeps working.
            State::forget('pending_token');
            State::forget('pending_at');
        }

        return array('ok' => true, 'enabled' => $on);
    }

    /**
     * What Asyntai says about the last read of the shared pages.
     *
     * @return array
     */
    private static function sync()
    {
        $siteId = State::siteId();

        if ($siteId === '') {
            return array('sync' => null);
        }

        $body = State::httpGet(State::origin() . '/api/v1/search-widget/status/?widget_id=' . rawurlencode($siteId), 8);
        $decoded = $body === null ? null : json_decode($body, true);
        $sync = is_array($decoded) && isset($decoded['sync']) && is_array($decoded['sync']) ? $decoded['sync'] : null;

        if ($sync === null) {
            return array('sync' => null);
        }

        return array('sync' => array(
            'status' => isset($sync['status']) ? (string) $sync['status'] : '',
            'count'  => isset($sync['count']) ? (int) $sync['count'] : 0,
            'error'  => isset($sync['error']) ? strip_tags((string) $sync['error']) : '',
        ));
    }

    /**
     * Two-letter code of the site language, for the sign-in window.
     *
     * @return string
     */
    private static function language()
    {
        $grav = Grav::instance();
        $code = '';

        try {
            $code = (string) $grav['language']->getLanguage();
        } catch (\Exception $e) {
            $code = '';
        }

        return substr($code !== '' ? $code : 'en', 0, 2);
    }
}
