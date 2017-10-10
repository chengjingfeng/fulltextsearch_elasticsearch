<?php
/**
 * FullNextSearch - Full Text Search your Nextcloud.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 */

namespace OCA\FullNextSearch_ElasticSearch\Platform;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\Curl\CouldNotConnectToHost;
use Elasticsearch\Common\Exceptions\MaxRetriesException;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Exception;
use OCA\FullNextSearch\INextSearchPlatform;
use OCA\FullNextSearch\INextSearchProvider;
use OCA\FullNextSearch\Model\DocumentAccess;
use OCA\FullNextSearch\Model\ExtendedBase;
use OCA\FullNextSearch\Model\SearchDocument;
use OCA\FullNextSearch\Model\SearchResult;
use OCA\FullNextSearch_ElasticSearch\Exceptions\ConfigurationException;
use OCA\FullNextSearch_ElasticSearch\Service\ConfigService;
use OCA\FullNextSearch_ElasticSearch\Service\MiscService;
use OCA\FullNextSearch_ElasticSearch\AppInfo\Application;


class ElasticSearchPlatform implements INextSearchPlatform {

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;

	/** @var Client */
	private $client;




	/**
	 * return a unique Id of the platform.
	 */
	public function getId() {
		return 'elastic_search';
	}

	/**
	 * return a unique Id of the platform.
	 */
	public function getName() {
		return 'ElasticSearch';
	}




	/**
	 * Called when loading the platform.
	 *
	 * Loading some container and connect to ElasticSearch.
	 *
	 * @throws ConfigurationException
	 */
	public function loadPlatform() {
		$app = new Application();

		$container = $app->getContainer();
		$this->configService = $container->query(ConfigService::class);
		$this->miscService = $container->query(MiscService::class);

		try {
			$this->connectToElastic($this->configService->getElasticHost());
		} catch (ConfigurationException $e) {
			throw $e;
		}
	}





	/**
	 * not used yet.
	 */
	public function testPlatform() {
	}


	/**
	 * called on init of the platform
	 *
	 * We create a general index.
	 */
	public function initPlatform() {
		$map = $this->generateGlobalMap();

		try {
			if (!$this->client->indices()
							  ->exists($this->generateGlobalMap(false))) {
				$this->client->indices()
							 ->create($map);
			}
		} catch (BadRequest400Exception $e) {
			throw new ConfigurationException(
				'Check your user/password and the index assigned to that cloud'
			);
		}
	}



	/**
	 * resetPlatform();
	 *
	 * Called when admin wants to reset the index, specific to a $provider.
	 * $provider can be null, meaning a reset of the whole index.
	 *
	 * @param INextSearchProvider|null $provider
	 */
	public function resetPlatform($provider) {
		$map = $this->generateGlobalMap(false);

		if ($provider instanceof INextSearchProvider) {
			// TODO: need to specify the map to remove
			// TODO: need to remove entries with type=providerId
			return;
		}

		try {
			$this->client->indices()
						 ->delete($map);
		} catch (Missing404Exception $e) {
			/* 404Exception will means that the mapping for that provider does not exist */
		}
	}



	/**
	 * {@inheritdoc}
	 */
	public function indexDocuments(INextSearchProvider $provider, $documents, $command) {
		foreach ($documents as $document) {

			if ($command !== null) {
				$command->hasBeenInterrupted();

				$this->interactWithCommandDuringIndex($command);
			}

			$this->indexDocument($provider, $document);
		}
	}


	/**
	 * @param INextSearchProvider $provider
	 * @param SearchDocument $document
	 */
	public function indexDocument(INextSearchProvider $provider, SearchDocument $document) {

		$access = $document->getAccess();
		$index = array();
		$index['index'] = $this->configService->getElasticIndex();
		$index['id'] = $document->getId();
		$index['type'] = $provider->getId();
		$index['body'] = [
			'title'   => $document->getTitle(),
			'content' => $document->getContent(),
			'owner'   => $access->getOwner(),
			'users'   => $access->getUsers(),
			'groups'  => $access->getGroups(),
			'circles' => $access->getCircles()
		];

		//echo json_encode($index);
		$result = $this->client->index($index);
		echo 'Indexing: ' . json_encode($result) . "\n";
	}





	/**
	 * {@inheritdoc}
	 */
	public function searchDocuments(INextSearchProvider $provider, DocumentAccess $access, $string) {

		$params = $this->generateSearchQuery($provider, $access, $string);

		$result = $this->client->search($params);
		$searchResult = $this->generateSearchResultFromResult($result);
		$searchResult->setProvider($provider);

		foreach ($result['hits']['hits'] as $entry) {
			$searchResult->addDocument($this->parseSearchEntry($entry, $access->getViewer()));
		}

		return $searchResult;
	}



	/**
	 * @param string $host
	 */
	private function connectToElastic($host) {

		try {
			$hosts = [MiscService::noEndSlash($host)];
			$this->client = ClientBuilder::create()
										 ->setHosts($hosts)
										 ->setRetries(2)
										 ->build();

		} catch (CouldNotConnectToHost $e) {
			echo 'CouldNotConnectToHost';
			$previous = $e->getPrevious();
			if ($previous instanceof MaxRetriesException) {
				echo "Max retries!";
			}
		} catch (Exception $e) {
			echo ' ElasticSearchPlatform::load() Exception --- ' . $e->getMessage() . "\n";
		}
	}


	/**
	 * @param ExtendedBase $command
	 */
	private function interactWithCommandDuringIndex(ExtendedBase $command) {

	}


	/**
	 * @param INextSearchProvider $provider
	 * @param DocumentAccess $access
	 * @param string $str
	 *
	 * @return array
	 */
	private function generateSearchQuery(INextSearchProvider $provider, DocumentAccess $access, $str) {

		$params = [
			'index' => $this->configService->getElasticIndex(),
			'type'  => $provider->getId()
		];

		$bool = [];
		$bool['must']['bool']['should'] =
			$this->generateSearchQueryContent($str);
		$bool['filter']['bool']['should'] =
			$this->generateSearchQueryAccess($access);
		$params['body']['query']['bool'] = $bool;

		$params['body']['highlight'] = $this->generateSearchHighlighting();

		return $params;
	}


	/**
	 * @param string $string
	 *
	 * @return array
	 */
	private function generateSearchQueryContent($string) {
		return [
			['match' => ['title' => $string]],
			['match' => ['content' => $string]]
		];
	}

	/**
	 * @param DocumentAccess $access
	 *
	 * @return array
	 */
	private function generateSearchQueryAccess(DocumentAccess $access) {

		$query = [];
		$query[] = ['match' => ['owner' => $access->getViewer()]];
		$query[] = ['match' => ['users' => $access->getViewer()]];

		foreach ($access->getGroups() as $group) {
			$query[] = ['match' => ['groups' => $group]];
		}

		foreach ($access->getCircles() as $circle) {
			['match' => ['circles' => $circle]];
		}

		return $query;
	}


	private function generateSearchHighlighting() {
		return [
			'fields'    => ['content' => new \stdClass()],
			'pre_tags'  => [''],
			'post_tags' => ['']
		];
	}

	/**
	 * @param array $result
	 *
	 * @return SearchResult
	 */
	private function generateSearchResultFromResult($result) {
		$searchResult = new SearchResult();
		$searchResult->setRawResult(json_encode($result));

		return $searchResult;
	}


	/**
	 * @param array $entry
	 * @param string $viewerId
	 *
	 * @return SearchDocument
	 */
	private function parseSearchEntry($entry, $viewerId) {
		$access = new DocumentAccess();
		$access->setViewer($viewerId);

		$document = new SearchDocument($entry['_id']);
		$document->setAccess($access);
		$document->setExcerpts($entry['highlight']['content']);
		$document->setScore($entry['_score']);
		$document->setTitle($entry['_source']['title']);

		return $document;
	}


	private function generateGlobalMap($complete = true) {

		$params = [
			'index' => $this->configService->getElasticIndex()
		];

		if ($complete === false) {
			return $params;
		}

		$params['body'] = [
			'settings' => [
				'analysis' => [
					'filter'      => [
						'shingle' => [
							'type' => 'shingle'
						]
					],
					'char_filter' => [
						'pre_negs'  => [
							'type'        => 'pattern_replace',
							'pattern'     => '(\\w+)\\s+((?i:never|no|nothing|nowhere|noone|none|not|havent|hasnt|hadnt|cant|couldnt|shouldnt|wont|wouldnt|dont|doesnt|didnt|isnt|arent|aint))\\b',
							'replacement' => '~$1 $2'
						],
						'post_negs' => [
							'type'        => 'pattern_replace',
							'pattern'     => '\\b((?i:never|no|nothing|nowhere|noone|none|not|havent|hasnt|hadnt|cant|couldnt|shouldnt|wont|wouldnt|dont|doesnt|didnt|isnt|arent|aint))\\s+(\\w+)',
							'replacement' => '$1 ~$2'
						]
					],
					'analyzer'    => [
						'analyzer' => [
							'type'      => 'custom',
							'tokenizer' => 'standard',
							'filter'    => ['lowercase', 'stop', 'kstem']
						]
					]
				]
			],
			'mappings' => [
				'_default_' => [
					'properties' => [
						'title'    => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'content'  => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'owner'    => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'users'    => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'groups'   => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'circles'  => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes',
							'copy_to'     => 'combined'
						],
						'combined' => [
							'type'        => 'text',
							'analyzer'    => 'analyzer',
							'term_vector' => 'yes'
						],
						'topics'   => [
							'type'  => 'text',
							'index' => 'not_analyzed'
						],
						'places'   => [
							'type'  => 'text',
							'index' => 'not_analyzed'
						]
					]
				]
			]
		];

		return $params;
	}
}