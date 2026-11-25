<?php
/**
 * Admin2 (Grav 2) endpoints for the chatbot settings page.
 *
 * Loaded only when the API plugin dispatches one of these routes. Calls are
 * authenticated by the API's own token, which Admin2 sends as a header, so
 * a page on another site cannot drive them through a signed-in browser.
 *
 * @package    Grav\Plugin\AsyntaiAiChatbot
 * @copyright  Asyntai
 * @license    MIT
 */

namespace Grav\Plugin\AsyntaiAiChatbot\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\AsyntaiAiChatbot\Connect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ChatbotController extends AbstractApiController
{
    /**
     * GET /asyntai-ai-chatbot/status
     *
     * @param ServerRequestInterface $request Request.
     *
     * @return ResponseInterface
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        return ApiResponse::create(Connect::status());
    }

    /**
     * POST /asyntai-ai-chatbot/action  {"task": "...", ...}
     *
     * @param ServerRequestInterface $request Request.
     *
     * @return ResponseInterface
     */
    public function action(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $body = $this->getRequestBody($request);
        $task = isset($body['task']) ? (string) $body['task'] : '';
        $user = $this->getUser($request);
        $email = $user && $user->get('email') ? (string) $user->get('email') : '';

        try {
            return ApiResponse::create(Connect::run($task, $body, $email));
        } catch (ApiException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();

            throw new ApiException($code >= 400 && $code < 600 ? $code : 500, 'Asyntai', $e->getMessage());
        }
    }
}
