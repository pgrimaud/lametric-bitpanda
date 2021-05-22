<?php

declare(strict_types=1);

namespace LaMetric;

use GuzzleHttp\Client as HttpClient;
use LaMetric\Helper\SymbolHelper;
use Predis\Client as RedisClient;
use LaMetric\Response\{Frame, FrameCollection};

class Api
{
    public const BITPANDA_API = 'https://api.bitpanda.com/v1/wallets/';
    public const CMC_API = 'https://web-api.coinmarketcap.com/v1/cryptocurrency/listings/latest?cryptocurrency_type=all&limit=4999&convert=';

    public function __construct(private HttpClient $httpClient, private RedisClient $redisClient, private array $credentials = [])
    {
    }

    /**
     * @param array $parameters
     *
     * @return FrameCollection
     */
    public function fetchData(array $parameters = []): FrameCollection
    {
        $redisKey   = 'lametric:bitpanda:prices:' . strtolower($parameters['currency']);
        $jsonPrices = $this->redisClient->get($redisKey);

        if (!$jsonPrices) {

            $cmcApi     = self::CMC_API . strtolower($parameters['currency']);
            $res        = $this->httpClient->request('GET', $cmcApi);
            $jsonPrices = (string) $res->getBody();

            $this->redisClient->set($redisKey, $jsonPrices, 'ex', 120);
        }

        $prices = json_decode($jsonPrices, true);

        $res = $this->httpClient->request('GET', self::BITPANDA_API, [
            'headers' => [
                'X-API-KEY' => $parameters['api-key'],
            ],
        ]);

        $json = (string) $res->getBody();

        $data = json_decode($json, true);

        $totalBalance = 0;

        foreach ($data['data'] as $wallet) {
            if ($wallet['attributes']['balance'] > 0) {
                foreach ($prices['data'] as $crypto) {
                    if ($crypto['symbol'] === $wallet['attributes']['cryptocoin_symbol']) {
                        $totalBalance += $crypto['quote']['USD']['price'] * $wallet['attributes']['balance'];
                        break;
                    }
                }
            }
        }

        return $this->mapData([
            'total' => SymbolHelper::getSymbol($parameters['currency']) . round($totalBalance, 2),
        ]);
    }

    /**
     * @param array $data
     *
     * @return FrameCollection
     */
    private function mapData(array $data = []): FrameCollection
    {
        $frameCollection = new FrameCollection();

        /**
         * Transform data as FrameCollection and Frame
         */
        $frame = new Frame();
        $frame->setText($data['total']);
        $frame->setIcon('45491');

        $frameCollection->addFrame($frame);

        return $frameCollection;
    }
}
