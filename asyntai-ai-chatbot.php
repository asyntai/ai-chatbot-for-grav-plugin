<?php
/**
 * Asyntai AI Chatbot for Grav.
 *
 * Puts the chat widget on the public site, runs the connect handshake from
 * the classic admin and from Admin2 (Grav 2), and serves the signed page
 * feed Asyntai reads when the owner shares their pages.
 *
 * @package    Grav\Plugin
 * @copyright  Asyntai
 * @license    MIT
 */

namespace Grav\Plugin;

use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\AsyntaiAiChatbot\Connect;
use Grav\Plugin\AsyntaiAiChatbot\Feed;
use Grav\Plugin\AsyntaiAiChatbot\State;
use RocketTheme\Toolbox\Event\Event;

// Our own classes, from classes/. The API plugin caches its routes, so the
// Admin2 controller must be loadable on requests that never fire
// onApiRegisterRoutes.
spl_autoload_register(function ($class) {
    $prefix = 'Grav\\Plugin\\AsyntaiAiChatbot\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

class AsyntaiAiChatbotPlugin extends Plugin
{
    /** Marks our own widget loader, so it never goes in twice. */
    const LOADER_MARK = 'setAttribute("data-asyntai-id",';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // Admin2 (Grav 2). These only fire when the API plugin is present.
            'onApiRegisterRoutes'  => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'    => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo'  => ['onApiPluginPageInfo', 0],
        ];
    }

    /**
     * @return void
     */
    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminMenu'              => ['onAdminMenu', 0],
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                'onTwigTemplatePaths'      => ['onTwigTemplatePaths', 0],
                // Answers before the admin's own handler (priority 1000),
                // which would treat a POST without its form nonce as forged.
                'onPagesInitialized'       => [['onAdminApi', 1001], ['onAdminPagesInitialized', 0]],
                'onTwigInitialized'        => ['onTwigInitialized', 0],
            ]);

            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onFeedRequest', 100000],
            'onTwigInitialized'  => ['onTwigInitialized', 0],
            'onOutputGenerated'  => ['onOutputGenerated', 0],
        ]);
    }

    // -----------------------------------------------------------------
    // Public site
    // -----------------------------------------------------------------

    /**
     * Answer the signed page feed. Anonymous by design: the signature is the
     * only key, and it is checked before anything is read.
     *
     * @return void
     */
    public function onFeedRequest(): void
    {
        $path = rtrim((string) $this->grav['uri']->path(), '/');

        if ($path === '/asyntai-chatbot/feed') {
            list($status, $body) = Feed::products($_GET);
        } elseif ($path === '/asyntai-chatbot/feed/pages') {
            if (isset($this->grav['twig'])) {
                $this->grav['twig']->init();
            }
            list($status, $body) = Feed::pages($_GET);
        } else {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Add the widget loader before </body>, once.
     *
     * @return void
     */
    public function onOutputGenerated(): void
    {
        $page = isset($this->grav['page']) ? $this->grav['page'] : null;

        if ($page && method_exists($page, 'templateFormat') && $page->templateFormat() !== 'html') {
            return;
        }

        $html = (string) $this->grav->output;

        if (strpos($html, self::LOADER_MARK) !== false) {
            return;
        }

        $loader = $this->loader();

        if ($loader === '') {
            return;
        }

        $at = strripos($html, '</body>');
        $this->grav->output = $at === false ? $html . $loader : substr_replace($html, $loader, $at, 0);
    }

    /**
     * @return void
     */
    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();

        // {{ asyntai_widget() }} for themes that place the widget themselves.
        $twig->addFunction(new \Twig\TwigFunction('asyntai_widget', function () {
            return $this->loader();
        }, ['is_safe' => ['html']]));

        if ($this->isAdmin()) {
            $twig->addFunction(new \Twig\TwigFunction('asyntai_chatbot_admin', function () {
                return $this->adminData();
            }));
        }
    }

    /**
     * The script that loads the widget after the page's load event, so an
     * unreachable widget server never delays the page.
     *
     * @return string
     */
    private function loader()
    {
        $siteId = State::siteId();

        if ($siteId === '') {
            return '';
        }

        return '<script>(function(){var l=function(){var s=document.createElement("script");s.src='
            . json_encode(State::scriptUrl()) . ';s.async=true;s.setAttribute("data-asyntai-id",'
            . json_encode($siteId) . ');document.head.appendChild(s)};if(document.readyState==="complete"){l()}'
            . 'else{window.addEventListener("load",l)}})();</script>';
    }

    // -----------------------------------------------------------------
    // Classic admin
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function onAdminMenu(): void
    {
        if (!isset($this->grav['twig']->plugins_hooked_nav)) {
            $this->grav['twig']->plugins_hooked_nav = [];
        }

        $this->grav['twig']->plugins_hooked_nav['Asyntai AI Chatbot'] = [
            'route' => 'asyntai',
            'icon'  => 'fa-comments',
        ];
    }

    /**
     * @param Event $event The event.
     *
     * @return void
     */
    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * @return void
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * @param Event $event The event.
     *
     * @return void
     */
    public function onAdminApi(Event $event): void
    {
        if (!$this->isOurRoute('asyntai/api')) {
            return;
        }

        try {
            $this->json(['success' => true, 'data' => $this->handleAdminApi()]);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            $this->json(['success' => false, 'message' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 500);
        }
    }

    /**
     * @param Event $event The event.
     *
     * @return void
     */
    public function onAdminPagesInitialized(Event $event): void
    {
        if (!$this->isOurRoute('asyntai')) {
            return;
        }

        $page = new Page();
        $page->init(new \SplFileInfo(__DIR__ . '/pages/asyntai.md'));
        $page->slug('asyntai');
        $this->grav['page'] = $page;
    }

    /**
     * A signed-in administrator who may manage plugins, a POST, and Grav's
     * own admin nonce, so a page on another site cannot drive this endpoint
     * through the owner's browser.
     *
     * @return array
     */
    private function handleAdminApi()
    {
        $user = $this->grav['user'];

        if (!$user || !$user->authenticated || !$user->authorize('admin.login')) {
            throw new \RuntimeException('Forbidden', 403);
        }

        if (!$user->authorize('admin.super') && !$user->authorize('admin.plugins')) {
            throw new \RuntimeException('Forbidden', 403);
        }

        if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
            throw new \RuntimeException('Method not allowed', 405);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($payload)) {
            $payload = [];
        }

        $nonce = isset($payload['admin_nonce']) ? (string) $payload['admin_nonce'] : '';

        if ($nonce === '' || !Utils::verifyNonce($nonce, 'admin-form')) {
            throw new \RuntimeException('Invalid security token. Please reload the page.', 403);
        }

        $task = isset($payload['task']) ? (string) $payload['task'] : '';

        return Connect::run($task, $payload, !empty($user->email) ? (string) $user->email : '');
    }

    /**
     * What the classic admin template needs.
     *
     * @return array
     */
    private function adminData()
    {
        $adminRoute = rtrim((string) $this->grav['uri']->rootUrl(false), '/')
            . rtrim((string) $this->config->get('plugins.admin.route', '/admin'), '/');

        return array_merge(Connect::status(), [
            'endpoint' => $adminRoute . '/asyntai/api',
            'nonce'    => Utils::getNonce('admin-form'),
        ]);
    }

    /**
     * Is this request for one of our admin routes? Anchored on the admin
     * route, so /admin/plugins/asyntai-ai-chatbot never matches.
     *
     * @param string $route Route under the admin, without slashes.
     *
     * @return bool
     */
    private function isOurRoute($route)
    {
        $path = rtrim((string) $this->grav['uri']->path(), '/');
        $admin = trim((string) $this->config->get('plugins.admin.route', '/admin'), '/');

        return (bool) preg_match('#/' . preg_quote($admin, '#') . '/' . preg_quote($route, '#') . '$#', $path);
    }

    // -----------------------------------------------------------------
    // Admin2 (Grav 2)
    // -----------------------------------------------------------------

    /**
     * @param Event $event The event.
     *
     * @return void
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\AsyntaiAiChatbot\Api\ChatbotController::class;
        $routes->get('/asyntai-ai-chatbot/status', [$controller, 'status']);
        $routes->post('/asyntai-ai-chatbot/action', [$controller, 'action']);
    }

    /**
     * @param Event $event The event.
     *
     * @return void
     */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'asyntai-ai-chatbot',
            'plugin'    => 'asyntai-ai-chatbot',
            'label'     => 'Asyntai AI Chatbot',
            'icon'      => 'fa-comments',
            'route'     => '/plugin/asyntai-ai-chatbot',
            'priority'  => 0,
            'authorize' => ['api.config.read'],
        ];
        $event['items'] = $items;
    }

    /**
     * The Admin2 page: our web component in admin-next/pages/.
     *
     * @param Event $event The event.
     *
     * @return void
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'asyntai-ai-chatbot') {
            return;
        }

        $event['definition'] = [
            'id'        => 'asyntai-ai-chatbot',
            'plugin'    => 'asyntai-ai-chatbot',
            'title'     => 'Asyntai AI Chatbot',
            'icon'      => 'fa-comments',
            'page_type' => 'component',
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param array $data   What to send.
     * @param int   $status HTTP status.
     *
     * @return void
     */
    private function json(array $data, $status = 200)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
