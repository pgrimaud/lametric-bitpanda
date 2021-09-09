<?php

declare(strict_types=1);

namespace LaMetric;

use GuzzleHttp\Client as HttpClient;
use LaMetric\Helper\{IconHelper, PriceHelper, SymbolHelper};
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
        $redisKey   = 'lametric:crypto-account:prices:' . strtolower($parameters['currency']);
        $jsonPrices = $this->redisClient->get($redisKey);

        if (!$jsonPrices) {
            $cmcApi     = self::CMC_API . strtolower($parameters['currency']);
            $res        = $this->httpClient->request('GET', $cmcApi);
            $jsonPrices = (string) $res->getBody();

            $this->redisClient->set($redisKey, $jsonPrices, 'ex', 600);
        }

        $prices = json_decode($jsonPrices, true);

        $res = $this->httpClient->request('GET', self::BITPANDA_API, [
            'headers' => [
                'X-API-KEY' => $parameters['api-key'],
            ],
        ]);

        $json = (string) $res->getBody();

        $data = json_decode($json, true);

        $wallets = [];

        foreach ($data['data'] as $wallet) {
            if ($wallet['attributes']['balance'] > 0) {
                foreach ($prices['data'] as $crypto) {
                    if ($crypto['symbol'] === $wallet['attributes']['cryptocoin_symbol']) {
                        if ($parameters['separate-assets'] === 'false') {
                            if (!isset($wallets['ALL'])) {
                                $wallets['ALL'] = 0;
                            }
                            $wallets['ALL'] += $crypto['quote'][strtoupper($parameters['currency'])]['price'] * $wallet['attributes']['balance'];
                        } else {
                            $price = $crypto['quote'][strtoupper($parameters['currency'])]['price'] * $wallet['attributes']['balance'];
                            if (($price > 1 && $parameters['hide-small-assets'] === 'true') || $parameters['hide-small-assets'] === 'false') {
                                $wallets[$crypto['symbol']] = $price;
                            }
                        }
                        break;
                    }
                }
            }
        }

        foreach ($wallets as &$wallet) {
            $wallet = match ($parameters['position']) {
                'hide' => PriceHelper::round($wallet),
                'after' => PriceHelper::round($wallet) . SymbolHelper::getSymbol($parameters['currency']),
                default => SymbolHelper::getSymbol($parameters['currency']) . PriceHelper::round($wallet),
            };
        }

        return $this->mapData($wallets);
    }

    /**
     * @param array $data
     *
     * @return FrameCollection
     */
    private function mapData(array $data = []): FrameCollection
    {
        $frameCollection = new FrameCollection();

        foreach ($data as $key => $wallet) {
            $frame = new Frame();
            $frame->setText((string)$wallet);
            $frame->setIcon(IconHelper::getIcon($key));

            $frameCollection->addFrame($frame);
        }


        return $frameCollection;
    }
}
