<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\File\CompiledYamlFile;
use RocketTheme\Toolbox\Event\Event;

class AsyntaiAiChatbotPlugin extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onPagesInitialized' => ['onAdminPagesInitialized', 0],
                'onTwigInitialized' => ['onTwigInitialized', 0],
            ]);
        } else {
            $this->enable([
                'onOutputGenerated' => ['onOutputGenerated', 0],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigInitialized' => ['onTwigInitialized', 0],
            ]);
        }
    }

    public function onAdminMenu(): void
    {
        if (!isset($this->grav['twig']->plugins_hooked_nav)) {
            $this->grav['twig']->plugins_hooked_nav = [];
        }
        $this->grav['twig']->plugins_hooked_nav['Asyntai AI Chatbot'] = [
            'route' => 'asyntai',
            'icon' => 'fa-comments',
        ];
    }

    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onAdminPagesInitialized(Event $event): void
    {
        $uri = $this->grav['uri'];
        $path = $uri->path();
        
        // Check if path contains the admin routes (works with any base path)
        if (strpos($path, '/admin/asyntai-save') !== false) {
            $this->handleSaveRequest();
            return;
        }
        if (strpos($path, '/admin/asyntai-reset') !== false) {
            $this->handleResetRequest();
            return;
        }
        if (strpos($path, '/admin/asyntai') !== false && strpos($path, '/admin/asyntai-') === false) {
            $this->renderAdminPage();
            return;
        }
    }

    private function renderAdminPage(): void
    {
        $page = new Page();
        $file = new \SplFileInfo(__DIR__ . '/pages/asyntai.md');
        $page->init($file);
        $page->slug('asyntai');
        $this->grav['page'] = $page;
    }

    private function handleSaveRequest(): void
    {
        if (isset($this->grav['debugger'])) {
            $this->grav['debugger']->addMessage('Asyntai save request');
        }
        $user = $this->grav['user'];
        if (!$user || !$user->authenticated) {
            $this->jsonResponse(['success' => false, 'error' => 'forbidden'], 403);
            return;
        }

        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);
        if (!is_array($payload) || !isset($payload['site_id'])) {
            $payload = $_POST ?: [];
        }
        $siteId = isset($payload['site_id']) ? trim((string)$payload['site_id']) : '';
        if ($siteId === '') {
            $this->jsonResponse(['success' => false, 'error' => 'missing site_id'], 400);
            return;
        }

        $current = (array)$this->config->get('plugins.asyntai-ai-chatbot');
        $current['site_id'] = $siteId;
        if (!empty($payload['script_url'])) {
            $current['script_url'] = trim((string)$payload['script_url']);
        }
        if (!empty($payload['account_email'])) {
            $current['account_email'] = trim((string)$payload['account_email']);
        }
        $this->writePluginConfig($current);

        $this->jsonResponse(['success' => true]);
    }

    private function handleResetRequest(): void
    {
        $user = $this->grav['user'];
        if (!$user || !$user->authenticated) {
            $this->jsonResponse(['success' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $current = (array)$this->config->get('plugins.asyntai-ai-chatbot');
        $current['site_id'] = '';
        $current['account_email'] = '';
        $this->writePluginConfig($current);
        $this->jsonResponse(['success' => true]);
    }

    private function writePluginConfig(array $data): void
    {
        $locator = $this->grav['locator'];
        $path = $locator->findResource('user://config/plugins/asyntai-ai-chatbot.yaml', true, true);
        $file = CompiledYamlFile::instance($path);
        $existing = (array)$file->content();
        $merged = array_merge($existing, $data);
        $file->save($merged);
        $file->free();

        // Also update runtime config for current request
        $this->config->set('plugins.asyntai-ai-chatbot', $merged);
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig']->twig();
        $twig->addFunction(new \Twig\TwigFunction('asyntai_widget', function () {
            $cfg = (array)$this->config->get('plugins.asyntai-ai-chatbot');
            $siteId = isset($cfg['site_id']) ? trim((string)$cfg['site_id']) : '';
            if ($siteId === '') {
                return '';
            }
            $scriptUrl = isset($cfg['script_url']) && trim((string)$cfg['script_url']) !== ''
                ? trim((string)$cfg['script_url'])
                : 'https://asyntai.com/static/js/chat-widget.js';
            $siteIdEsc = htmlspecialchars($siteId, ENT_QUOTES, 'UTF-8');
            $scriptUrlEsc = htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8');
            return '<script async defer src="' . $scriptUrlEsc . '" data-asyntai-id="' . $siteIdEsc . '"></script>';
        }));
    }

    public function onOutputGenerated(): void
    {
        $cfg = (array)$this->config->get('plugins.asyntai-ai-chatbot');
        $enabled = isset($cfg['enabled']) ? (bool)$cfg['enabled'] : true;
        if (!$enabled) {
            return;
        }
        $siteId = isset($cfg['site_id']) ? trim((string)$cfg['site_id']) : '';
        if ($siteId === '') {
            return;
        }
        $scriptUrl = isset($cfg['script_url']) && trim((string)$cfg['script_url']) !== ''
            ? trim((string)$cfg['script_url'])
            : 'https://asyntai.com/static/js/chat-widget.js';

        $injection = '<script type="text/javascript">(function(){var s=document.createElement("script");s.async=true;s.defer=true;s.src=' .
            json_encode($scriptUrl) . ';s.setAttribute("data-asyntai-id",' . json_encode($siteId) . ');s.charset="UTF-8";var f=document.getElementsByTagName("script")[0];if(f&&f.parentNode){f.parentNode.insertBefore(s,f);}else{(document.head||document.documentElement).appendChild(s);}})();</script>';

        $content = (string)$this->grav->output;
        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $injection . '</body>', $content);
        } else {
            $content .= $injection;
        }
        $this->grav->output = $content;
    }
}


