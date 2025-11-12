<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ImportActivity\ImportActivity;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use App\Infrastructure\Serialization\Json;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final readonly class StravaWebhookRequestHandler
{
    public function __construct(
        private CommandBus $commandBus,
        private LoggerInterface $logger,
        private ?string $webhookVerifyToken,
    ) {
    }

    #[Route(path: '/webhook/strava', methods: ['GET', 'POST'], priority: 10)]
    public function handle(Request $request): Response
    {
        // -----------------------------------------------------------------
        // Handle Strava's one-time GET validation request
        // -----------------------------------------------------------------
        if ($request->isMethod('GET')) {
            $this->logger->info('Received Strava webhook validation request');

            $mode = $request->query->get('hub_mode');
            $token = $request->query->get('hub_verify_token');
            $challenge = $request->query->get('hub_challenge');

            if (empty($this->webhookVerifyToken)) {
                $this->logger->error('WEBHOOK_VERIFY_TOKEN is not set in your .env file.');
                return new Response('Webhook not configured', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if ('subscribe' === $mode && $this->webhookVerifyToken === $token) {
                $this->logger->info('Strava webhook validation successful');

                return new JsonResponse(['hub.challenge' => $challenge]);
            }

            $this->logger->warning('Strava webhook validation failed', [
                'mode' => $mode,
                'token' => $token,
            ]);

            return new Response('Webhook validation failed', Response::HTTP_FORBIDDEN);
        }

        // -----------------------------------------------------------------
        // Handle Strava's POST notification (e.g., new activity)
        // -----------------------------------------------------------------
        if ($request->isMethod('POST')) {
            $this->logger->info('Received Strava webhook POST notification');
            $payload = Json::decode($request->getContent());

            if (empty($payload['object_type']) || empty($payload['aspect_type']) || empty($payload['object_id'])) {
                $this->logger->warning('Webhook payload missing required fields', $payload);

                return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
            }

            // Check if it's a new activity
            if ('activity' === $payload['object_type'] && 'create' === $payload['aspect_type']) {
                $activityId = ActivityId::from($payload['object_id']);
                $this->logger->info(sprintf('New activity received via webhook: %s', $activityId));

                // Dispatch the command from Part 1
                $this->commandBus->dispatch(new ImportActivity(
                    $activityId,
                    new LoggableConsoleOutput($this->logger) // Use a logger-backed output
                ));
            } else {
                // Log other event types (like activity.update or activity.delete)
                $this->logger->info('Received non-create activity event, skipping', $payload);
            }

            // Respond to Strava immediately with 200 OK
            return new Response('OK', Response::HTTP_OK);
        }

        return new Response('Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
