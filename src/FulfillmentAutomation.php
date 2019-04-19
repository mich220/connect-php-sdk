<?php

/**
 * This file is part of the Ingram Micro Cloud Blue Connect SDK.
 *
 * @copyright (c) 2019. Ingram Micro. All Rights Reserved.
 */

namespace Connect;

use GuzzleHttp\ClientInterface;
use Pimple\Container;
use Pimple\Psr11\Container as PSRContainer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class FulfillmentAutomation
 * @property Config $config
 * @property LoggerInterface $logger
 * @property ClientInterface $http
 *
 * @package Connect
 */
abstract class FulfillmentAutomation extends AutomationEngine implements FulfillmentAutomationInterface
{

    /**
     * Send the actual request to the connect endpoint
     * @param string $verb
     * @param string $path
     * @param null|Model|string $body
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendRequest($verb, $path, $body = null)
    {
        if ($body instanceof \Connect\Model) {
            $body = $body->toJSON(true);
        }

        $headers = [
            'Authorization' => 'ApiKey ' . $this->config->apiKey,
            'Request-ID' => uniqid('api-request-'),
            'Content-Type' => 'application/json',
        ];

        $this->logger->info('HTTP Request: ' . strtoupper($verb) . ' ' . $this->config->apiEndpoint . $path);
        $this->logger->debug("Request Headers:\n" . print_r($headers, true));

        if (isset($body)) {
            $this->logger->debug("Request Body:\n" . $body);
        }

        $response = $this->http->request(strtoupper($verb), trim($this->config->apiEndpoint . $path), [
            'body' => $body,
            'headers' => $headers
        ]);

        $this->logger->info('HTTP Code: ' . $response->getStatusCode());

        return $response->getBody()->getContents();
    }

    /**
     * Process all requests
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function process()
    {
        foreach ($this->listTierConfigs(['status' => 'pending']) as $tierConfig) {
            $this->dispatchTierConfig($tierConfig);
        }
        foreach ($this->listRequests(['status' => 'pending']) as $request) {
            $this->dispatch($request);
        }
    }

    /**
     * @param TierConfigRequest $tierConfigRequest
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function dispatchTierConfig($tierConfigRequest)
    {
        try {
            if ($this->config->products && !in_array(
                $tierConfigRequest->configuration->product->id,
                $this->config->products
            )) {
                return 'Invalid product';
            }

            $processingResult = 'unknown';

            $this->logger->info("Starting processing Tier Config ID=" . $tierConfigRequest->id);

            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $msg = $this->processTierConfigRequest($tierConfigRequest);
            if (!$msg || is_string($msg)) {
                $msg = new ActivationTileResponse($msg);
            }

            if ($msg instanceof ActivationTemplateResponse) {
                $this->sendRequest(
                    'POST',
                    '/tier/config-requests/' . $tierConfigRequest->id . '/approve',
                    '{"template": {"id": "' . $msg->templateid . '"}}'
                );
                $processingResult = 'succeed (Activated using template ' . $msg->templateid . ')';
            } else {
                $this->sendRequest(
                    'POST',
                    '/tier/config-requests/' . $tierConfigRequest->id . '/approve',
                    '{"template": {"representation": "' . $msg->activationTile . '"}}'
                );
                $processingResult = 'succeed (' . $msg->activationTile . ')';
            }
        } catch (Inquire $e) {
            // update parameters and move to inquire
            $this->updateTierConfigRequestParameters($tierConfigRequest, $e->params);//WORKING HERE!
            $this->sendRequest('POST', '/tier/config-requests/' . $tierConfigRequest->id . '/inquire', '{}');
            $processingResult = 'inquire';
        } catch (Fail $e) {
            // fail request
            $this->sendRequest(
                'POST',
                '/tier/config-requests/' . $tierConfigRequest->id . '/fail',
                '{"reason": "' . $e->getMessage() . '"}'
            );
            $processingResult = 'fail';
        } catch (Skip $e) {
            $processingResult = 'skip';
        }

        $this->logger->info("Finished processing of Tier Config Request with ID=" . $tierConfigRequest->id . " result=" . $processingResult);

        return $processingResult;
    }

    /**
     * @param Request $request
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function dispatch($request)
    {
        try {
            if ($this->config->products && !in_array($request->asset->product->id, $this->config->products)) {
                return 'Invalid product';
            }

            $processingResult = 'unknown';

            $this->logger->info("Starting processing of request ID=" . $request->id);

            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $msg = $this->processRequest($request);
            if (!$msg || is_string($msg)) {
                $msg = new ActivationTileResponse($msg);
            }

            if ($msg instanceof ActivationTemplateResponse) {
                $this->sendRequest(
                    'POST',
                    '/requests/' . $request->id . '/approve',
                    '{"template_id": "' . $msg->templateid . '"}'
                );
                $processingResult = 'succeed (Activated using template ' . $msg->templateid . ')';
            } else {
                $this->sendRequest(
                    'POST',
                    '/requests/' . $request->id . '/approve',
                    '{"activation_tile": "' . $msg->activationTile . '"}'
                );
                $processingResult = 'succeed (' . $msg->activationTile . ')';
            }
        } catch (Inquire $e) {
            // update parameters and move to inquire
            $this->updateParameters($request, $e->params);
            $this->sendRequest('POST', '/requests/' . $request->id . '/inquire', '{}');
            $processingResult = 'inquire';
        } catch (Fail $e) {
            // fail request
            $this->sendRequest(
                'POST',
                '/requests/' . $request->id . '/fail',
                '{"reason": "' . $e->getMessage() . '"}'
            );
            $processingResult = 'fail';
        } catch (Skip $e) {
            $processingResult = 'skip';
        }

        $this->logger->info("Finished processing of request ID=" . $request->id . " result=" . $processingResult);

        return $processingResult;
    }

    /**
     * List the pending requests
     * @param array $filters Filter for listing key->value or key->array(value1, value2)
     * @return array|Model
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function listRequests(array $filters = null)
    {
        $query = '';

        if ($this->config->products) {
            $filters['asset.product.id__in'] = implode(",", $this->config->products);
        }

        if ($filters) {
            $query = http_build_query($filters);

            // process case when value for filter is array
            $query = '?' . preg_replace('/%5B[0-9]+%5D/simU', '', $query);

            $query = urldecode($query);
        }
        $body = $this->sendRequest('GET', '/requests' . $query);

        /** @var Request[] $models */
        $models = Model::modelize('requests', json_decode($body));
        foreach ($models as $index => $model) {
            $models[$index]->requestProcessor = $this;
        }

        return $models;
    }

    /**
     * List the pending tier/Config-requests
     * @param array $filters Filter for listing key->value or key->array(value1, value2)
     * @return array|TierConfigRequest
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function listTierConfigs(array $filters = null)
    {
        $query = '';

        if ($filters) {
            $query = http_build_query($filters);

            // process case when value for filter is array
            $query = '?' . preg_replace('/%5B[0-9]+%5D/simU', '', $query);
        }

        $body = $this->sendRequest('GET', '/tier/config-requests' . $query);

        /** @var Request[] $models */
        $models = Model::modelize('tierConfigRequests', json_decode($body));
        foreach ($models as $index => $model) {
            $models[$index]->requestProcessor = $this;
        }

        return $models;
    }

    /**
     * Update request parameters
     * @param Request $request - request being updated
     * @param Param[] $params - array of parameters
     *      Example:
     *          array(
     *              $request->asset->params['param_a']->error('Unknown activation ID was provided'),
     *              $request->asset->params['param_b']->value('true'),
     *              new \Connect\Param(['id' => 'param_c', 'newValue'])
     *          )
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateParameters(Request $request, array $params)
    {
        $body = new \Connect\Request(['asset' => ['params' => $params]]);
        $this->sendRequest('PUT', '/requests/' . $request->id, $body);
    }

    /**
     * Update tierConfig parameters
     * @param TierConfigRequest $tierConfigRequest - TierConfigRequest being updated
     * @param Param[] $params - array of parameters
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateTierConfigRequestParameters(TierConfigRequest $tierConfigRequest, array $params)
    {
        $body = new \Connect\TierConfigRequest(['params' => $params]);
        $this->sendRequest('PUT', '/tier/config-requests/' . $tierConfigRequest->id, $body);
    }

    /**
     * Gets Activation template for a given request
     * @param $templateId - ID of template requested
     * @param $request - ID of request or Request object
     * @return string - Rendered template
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function renderTemplate($templateId, $request)
    {
        $query = ($request instanceof Request) ? $request->id : $request;
        return $this->sendRequest('GET', '/templates/' . $templateId . '/render?request_id=' . $query);
    }

    /**
     * @param $tierId - Connect ID of the tier
     * @param $productId - Product ID
     * @return array|Param
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getTierConfigByProduct($tierId, $productId)
    {
        $body = $this->sendRequest(
            'GET',
            '/tier/config-requests?status=approved&configuration__product__id=' . $productId . '&configuration__account__id=' . $tierId
        );
        $model = Model::modelize('tierConfigRequests', json_decode($body));
        if (count($model) > 0) {
            return $model[0]->configuration;
        }
        return $model;
    }

    /**
     * @param $parameterId
     * @param $tierId
     * @param $productId
     * @return Param|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getTierParameterByProductAndTierId($parameterId, $tierId, $productId)
    {
        $tierConfig = $this->getTierConfigByProduct($tierId, $productId);
        if (!$tierConfig) {
            return null;
        }
        $param = current(array_filter($tierConfig->params, function (Param $param) use ($parameterId) {
            return ($param->id === $parameterId);
        }));

        return ($param) ? $param : null;
    }
}
