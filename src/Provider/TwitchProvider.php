<?php

namespace App\Provider;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class TwitchProvider implements PlatformProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function updateStreamTitleAndCategory(Account $account, string $title, string $category): bool
    {
        $client = HttpClient::create();
        if ($category !== '') {
            try {
                $response = $client->request(
                    'GET',
                    'https://api.twitch.tv/helix/search/categories?query=' . $category . '&first=1', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $account->getAccessToken(),
                            'Content-Type' => 'application/json',
                            'Client-Id' => $_ENV['OAUTH_TWITCH_CLIENT_ID']
                        ]
                    ]
                );
                if ($response->getStatusCode() == 401) {
                    $response->cancel();
                    $account = $this->refreshTokenAndRetryRequest($account);
                    if (!$account) return false;
                } else if ($response->getStatusCode() >= 300) {
                    return false;
                }

                $responseData = $response->toArray();
                $category = $responseData['data'][0]['id'];
            } catch (TransportExceptionInterface | ClientExceptionInterface | DecodingExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
                dd($e);
            }

        }

        try {
            $response = $client->request(
                'PATCH',
                'https://api.twitch.tv/helix/channels?broadcaster_id=' . $account->getExternalId(), [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $account->getAccessToken(),
                        'Content-Type' => 'application/json',
                        'Client-Id' => $_ENV['OAUTH_TWITCH_CLIENT_ID']
                    ],
                    'json' => [
                        'game_id' => $category,
                        'title' => $title,
                        'broadcaster_language' => 'fr'
                    ]
                ]
            );

            if ($response->getStatusCode() >= 300) {
                return false;
            }
        } catch (TransportExceptionInterface | ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface $e) {
            dump($e);
        }


        return true;
    }


    private function refreshTokenAndRetryRequest(Account $account): ?Account
    {
        $client = HttpClient::create();
        try {
            $response = $client->request('POST', 'https://id.twitch.tv/oauth2/token?grant_type=refresh_token&refresh_token=' .
                $account->getRefreshToken() . '&client_id=' . $_ENV['OAUTH_TWITCH_CLIENT_ID'] . '&client_secret=' . $_ENV['OAUTH_TWITCH_CLIENT_SECRET']);
            if ($response->getStatusCode() >= 300) {
                return null;
            }
        } catch (TransportExceptionInterface $e) {
            return null;
        }
        try {
            $account->setAccessToken(json_decode($response->getContent())->access_token);
        } catch (ClientExceptionInterface | RedirectionExceptionInterface | ServerExceptionInterface | TransportExceptionInterface $e) {
            return null;
        }
        $this->entityManager->flush();
        // TODO : Retry the request
        return $account;
    }
}
