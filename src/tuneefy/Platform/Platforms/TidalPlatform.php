<?php

namespace tuneefy\Platform\Platforms;

use tuneefy\MusicalEntity\Entities\AlbumEntity;
use tuneefy\MusicalEntity\Entities\TrackEntity;
use tuneefy\Platform\Platform;
use tuneefy\Platform\PlatformException;
use tuneefy\Platform\PlatformResult;
use tuneefy\Platform\WebStreamingPlatformInterface;
use tuneefy\Utils\Utils;

class TidalPlatform extends Platform implements WebStreamingPlatformInterface
{
    const NAME = 'Tidal';
    const HOMEPAGE = 'http://tidal.com/';
    const TAG = 'tidal';
    const COLOR = '00FFFF';

    const API_ENDPOINT = 'https://listen.tidalhifi.com/v1/';
    const API_METHOD = Platform::METHOD_GET;

    protected $endpoints = [
        Platform::LOOKUP_TRACK => self::API_ENDPOINT.'tracks/%s',
        Platform::LOOKUP_ALBUM => self::API_ENDPOINT.'albums/%s',
        Platform::LOOKUP_ARTIST => self::API_ENDPOINT.'artists/%s',
        Platform::SEARCH_TRACK => self::API_ENDPOINT.'search/tracks',
        Platform::SEARCH_ALBUM => self::API_ENDPOINT.'search/albums',
       // Platform::SEARCH_ARTIST => self::API_ENDPOINT . "search/artists"
    ];
    protected $terms = [
        Platform::LOOKUP_TRACK => null,
        Platform::LOOKUP_ALBUM => null,
        Platform::LOOKUP_ARTIST => null,
        Platform::SEARCH_TRACK => 'query',
        Platform::SEARCH_ALBUM => 'query',
       // Platform::SEARCH_ARTIST => "query"
    ];
    protected $options = [
        Platform::LOOKUP_TRACK => ['countryCode' => 'FR'],
        Platform::LOOKUP_ALBUM => ['countryCode' => 'FR'],
        Platform::LOOKUP_ARTIST => ['countryCode' => 'FR'],
        Platform::SEARCH_TRACK => ['countryCode' => 'FR', 'limit' => Platform::LIMIT],
        Platform::SEARCH_ALBUM => ['countryCode' => 'FR', 'limit' => Platform::LIMIT],
       // Platform::SEARCH_ARTIST => Map { "countryCode" => "FR", "limit" => Platform::LIMIT }
    ];

    // http://www.tidal.com/track/40358305
    const REGEX_TIDAL_TRACK = "/track\/(?P<track_id>".Platform::REGEX_NUMERIC_ID.")[\/]?$/";
    // http://www.tidal.com/album/571179
    const REGEX_TIDAL_ALBUM = "/\/album\/(?P<album_id>".Platform::REGEX_NUMERIC_ID.")[\/]?$/";
    // http://www.tidal.com/artist/3528326
    const REGEX_TIDAL_ARTIST = "/artist\/(?P<artist_id>".Platform::REGEX_FULLSTRING.")[\/]?$/";

    public function hasPermalink(string $permalink): bool
    {
        return false !== strpos($permalink, 'tidal.') || false !== strpos($permalink, 'tidalhifi.');
    }

    protected function addContextOptions(array $data): array
    {
        $data['token'] = $this->key;

        return $data;
    }

    private function getCoverUrlFromCoverHash(?string $cover_hash = ''): string
    {
        return sprintf('http://resources.wimpmusic.com/images/%s/320x320.jpg', str_replace('-', '/', $cover_hash));
    }

    public function expandPermalink(string $permalink, int $mode): PlatformResult
    {
        $musical_entity = null;
        $query_words = [$permalink];

        $match = [];

        if (preg_match(self::REGEX_TIDAL_TRACK, $permalink, $match)) {
            $response = self::fetch($this, Platform::LOOKUP_TRACK, $match['track_id']);

            if (null === $response || (property_exists($response->data, 'status') && 'error' === $response->data->status)) {
                throw new PlatformException($this);
            }

            $entity = $response->data;
            $musical_entity = new TrackEntity($entity->title, new AlbumEntity($entity->album->title, $entity->artist->name, $this->getCoverUrlFromCoverHash($entity->album->cover)));
            $musical_entity->addLink(static::TAG, $entity->url);

            $query_words = [
                $musical_entity->getAlbum()->getArtist(),
                $musical_entity->getSafeTitle(),
            ];
        } elseif (preg_match(self::REGEX_TIDAL_ALBUM, $permalink, $match)) {
            $response = self::fetch($this, Platform::LOOKUP_ALBUM, $match['album_id']);

            if (null === $response || (property_exists($response->data, 'status') && 'error' === $response->data->status)) {
                throw new PlatformException($this);
            }

            $entity = $response->data;
            $musical_entity = new AlbumEntity($entity->title, $entity->artist->name, $this->getCoverUrlFromCoverHash($entity->cover));
            $musical_entity->addLink(static::TAG, $entity->url);

            $query_words = [
                $musical_entity->getArtist(),
                $musical_entity->getSafeTitle(),
            ];
        } elseif (preg_match(self::REGEX_TIDAL_ARTIST, $permalink, $match)) {
            $response = self::fetch($this, Platform::LOOKUP_ARTIST, $match['artist_id']);

            if (null === $response || (property_exists($response->data, 'status') && 'error' === $response->data->status)) {
                throw new PlatformException($this);
            }

            $query_words = [$response->data->name];
        }

        // Consolidate results
        $metadata = ['query_words' => $query_words];

        if (null !== $musical_entity) {
            $metadata['platform'] = $this->getName();
        }

        return new PlatformResult($metadata, $musical_entity);
    }

    public function extractSearchResults(\stdClass $response, int $type, string $query, int $limit, int $mode): array
    {
        $entities = $response->data->items;

        // We actually don't pass the limit to the fetch()
        // request since it's not really useful, in fact
        $length = min(count($entities), $limit ? $limit : Platform::LIMIT);

        $musical_entities = [];

        // Normalizing each track found
        for ($i = 0; $i < $length; ++$i) {
            $current_item = $entities[$i];

            if (Platform::SEARCH_TRACK === $type) {
                $musical_entity = new TrackEntity($current_item->title, new AlbumEntity($current_item->album->title, $current_item->artist->name, $this->getCoverUrlFromCoverHash($current_item->album->cover)));
                $musical_entity->addLink(static::TAG, $current_item->url);
            } else /*if ($type === Platform::SEARCH_ALBUM)*/ {
                $musical_entity = new AlbumEntity($current_item->title, $current_item->artist->name, $this->getCoverUrlFromCoverHash($current_item->cover));
                $musical_entity->addLink(static::TAG, $current_item->url);
            }

            // Tidal has a $current_item->popularity key, but right now, it's kind of ... empty.
            $musical_entities[] = new PlatformResult(['score' => Utils::indexScore($i)], $musical_entity);
        }

        return $musical_entities;
    }
}
