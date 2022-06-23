<?php

declare(strict_types=1);

namespace LaMetric;

use GuzzleHttp\Client as HttpClient;
use LaMetric\Helper\IconHelper;
use LaMetric\Helper\PriceHelper;
use LaMetric\Helper\SymbolHelper;
use Predis\Client as RedisClient;
use LaMetric\Response\Frame;
use LaMetric\Response\FrameCollection;

class Api
{
    public const BITPANDA_API_WALLET = 'https://api.bitpanda.com/v1/wallets/';
    public const BITPANDA_API_FIAT = 'https://api.bitpanda.com/v1/fiatwallets/';

    public function __construct(
        private HttpClient  $httpClient,
        private RedisClient $redisClient
    ) {
    }

    public function fetchData(array $parameters = []): FrameCollection
    {
        $pricesFile = $this->redisClient->get('lametric:cryptocurrencies');
        $prices = json_decode((string)$pricesFile, true);

        $res = $this->httpClient->request('GET', self::BITPANDA_API_WALLET, [
            'headers' => [
                'X-API-KEY' => $parameters['api-key'],
            ],
        ]);

        $json = (string)$res->getBody();

        $data = json_decode($json, true);

        $wallets = [];

        foreach ($data['data'] as $wallet) {
            if ($wallet['attributes']['balance'] > 0) {
                if (isset($prices[$wallet['attributes']['cryptocoin_symbol']])) {
                    $asset = $prices[$wallet['attributes']['cryptocoin_symbol']];
                    if ($parameters['separate-assets'] === 'false') {
                        if (!isset($wallets['ALL'])) {
                            $wallets['ALL'] = 0;
                        }
                        $wallets['ALL'] += $asset['price'] * $wallet['attributes']['balance'];
                    } else {
                        $price = $asset['price'] * $wallet['attributes']['balance'];
                        if (($price > 1 && $parameters['hide-small-assets'] === 'true') || $parameters['hide-small-assets'] === 'false') {
                            $wallets[$wallet['attributes']['cryptocoin_symbol']] = $price;
                        }
                    }
                }
            }
        }

        if ($parameters['fiat'] === 'true') {
            $res = $this->httpClient->request('GET', self::BITPANDA_API_FIAT, [
                'headers' => [
                    'X-API-KEY' => $parameters['api-key'],
                ],
            ]);

            $json = (string)$res->getBody();
            $data = json_decode($json, true);

            foreach ($data['data'] as $currency) {
                if ((float)$currency['attributes']['balance'] > 0) {
                    $amount = (float)$currency['attributes']['balance'];

                    if ($parameters['separate-assets'] === 'false') {
                        if (!isset($wallets['ALL'])) {
                            $wallets['ALL'] = 0;
                        }
                        $wallets['ALL'] += $amount;
                    } else {
                        $price = $amount;
                        if (($price > 1 && $parameters['hide-small-assets'] === 'true') || $parameters['hide-small-assets'] === 'false') {
                            $wallets[$currency['attributes']['fiat_symbol']] = $amount;
                        }
                    }
                }
            }
        }

        foreach ($wallets as &$wallet) {
            $wallet = $wallet * $this->convertToCurrency($parameters['currency']);

            $wallet = match ($parameters['position']) {
                'hide' => PriceHelper::round($wallet),
                'after' => PriceHelper::round($wallet) . SymbolHelper::getSymbol($parameters['currency']),
                default => SymbolHelper::getSymbol($parameters['currency']) . PriceHelper::round($wallet),
            };
        }

        return $this->mapData($wallets);
    }

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

    private function convertToCurrency(string $currencyToShow): float|int
    {
        if ($currencyToShow === 'USD') {
            return 1;
        }

        $pricesFile = $this->redisClient->get('lametric:forex');
        $rates = json_decode((string)$pricesFile, true);

        if (!isset($rates[$currencyToShow])) {
            throw new \Exception(sprintf('Currency %s not found', $currencyToShow));
        }

        return $rates[$currencyToShow];
    }
}
