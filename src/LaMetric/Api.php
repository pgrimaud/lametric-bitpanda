<?php

declare(strict_types=1);

namespace LaMetric;

use Codenixsv\CoinGeckoApi\CoinGeckoClient;
use GuzzleHttp\Client as HttpClient;
use LaMetric\Helper\{IconHelper, PriceHelper, SymbolHelper};
use Predis\Client as RedisClient;
use LaMetric\Response\{Frame, FrameCollection};

class Api
{
    public const BITPANDA_API = 'https://api.bitpanda.com/v1/wallets/';
    public const CMC_API = 'https://web-api.coinmarketcap.com/v1/cryptocurrency/listings/latest?cryptocurrency_type=all&limit=4999&convert=';

    public function __construct(
        private HttpClient  $httpClient,
        private RedisClient $redisClient)
    {
    }

    /**
     * @param array $parameters
     *
     * @return FrameCollection
     */
    public function fetchData(array $parameters = []): FrameCollection
    {
        $redisKey = 'lametric:cryptocurrencies:' . strtolower($parameters['currency']);
        $jsonPrices = $this->redisClient->get($redisKey);

        if (!$jsonPrices) {
            $cmcApi = self::CMC_API . strtolower($parameters['currency']);
            $res = $this->httpClient->request('GET', $cmcApi);
            $jsonPrices = (string)$res->getBody();

            $prices = $this->formatData(json_decode($jsonPrices, true), $parameters['currency']);

            $this->redisClient->set($redisKey, json_encode($prices), 'ex', 300);
        } else {
            $prices = json_decode($jsonPrices, true);
        }

        $res = $this->httpClient->request('GET', self::BITPANDA_API, [
            'headers' => [
                'X-API-KEY' => $parameters['api-key'],
            ],
        ]);

        $json = (string)$res->getBody();

        $data = json_decode($json, true);

        $wallets = [];

        foreach ($data['data'] as $wallet) {
            if ($wallet['attributes']['balance'] > 0) {
                if ($wallet['attributes']['cryptocoin_symbol'] === 'PAN') {
                    $client = new CoinGeckoClient();
                    $data = $client->simple()->getPrice('pantos', $parameters['currency']);
                    $missingCrypto = [
                        'PAN' => [
                            'short' => 'PAN',
                            'price' => $data['pantos'][strtolower($parameters['currency'])]
                        ]
                    ];
                    $prices = array_merge($prices, $missingCrypto);
                }

                if (isset($prices[$wallet['attributes']['cryptocoin_symbol']])) {
                    $asset = $prices[$wallet['attributes']['cryptocoin_symbol']];
                    if ($asset['short'] === $wallet['attributes']['cryptocoin_symbol']) {
                        if ($parameters['separate-assets'] === 'false') {
                            if (!isset($wallets['ALL'])) {
                                $wallets['ALL'] = 0;
                            }
                            $wallets['ALL'] += $asset['price'] * $wallet['attributes']['balance'];
                        } else {
                            $price = $asset['price'] * $wallet['attributes']['balance'];
                            if (($price > 1 && $parameters['hide-small-assets'] === 'true') || $parameters['hide-small-assets'] === 'false') {
                                $wallets[$asset['short']] = $price;
                            }
                        }
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

    /**
     * @param array $sources
     * @param string $currencyToShow
     *
     * @return array
     */
    private function formatData(array $sources, string $currencyToShow): array
    {
        $data = [];

        foreach ($sources['data'] as $crypto) {
            // manage multiple currencies with the same symbol
            // & override VAL value
            if (!isset($data[$crypto['symbol']]) || $crypto['symbol'] === 'VAL') {

                // manage error on results // maybe next time?
                if (!isset($crypto['quote'][$currencyToShow]['price'])) {
                    exit;
                }

                $data[$crypto['symbol']] = [
                    'short' => $crypto['symbol'],
                    'price' => $crypto['quote'][$currencyToShow]['price'],
                    'change' => round((float)$crypto['quote'][$currencyToShow]['percent_change_24h'], 2),
                ];
            }
        }

        return $data;
    }
}
