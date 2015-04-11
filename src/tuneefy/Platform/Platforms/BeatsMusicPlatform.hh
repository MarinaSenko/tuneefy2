<?hh // strict

namespace tuneefy\Platform\Platforms;

use tuneefy\Platform\Platform,
    tuneefy\Platform\PlatformResult,
    tuneefy\Platform\WebStreamingPlatformInterface,
    tuneefy\MusicalEntity\MusicalEntity,
    tuneefy\MusicalEntity\Entities\TrackEntity,
    tuneefy\MusicalEntity\Entities\AlbumEntity,
    tuneefy\Utils\Utils;

class BeatsMusicPlatform extends Platform implements WebStreamingPlatformInterface
{
  
  const string NAME = "Beats Music";
  const string TAG = "beats";
  const string COLOR = "E31937";

  const string API_ENDPOINT = "https://partner.api.beatsmusic.com/v1/api/";
  const string API_METHOD = Platform::METHOD_GET;

  const bool NEEDS_KEY = true;
  protected string $key_param = "client_id";

  protected ImmMap<int,?string> $endpoints = ImmMap {
    Platform::LOOKUP_TRACK  => self::API_ENDPOINT . "tracks/%s",
    Platform::LOOKUP_ALBUM  => self::API_ENDPOINT . "albums/%s",
    Platform::LOOKUP_ARTIST => self::API_ENDPOINT . "artists/%s",
    Platform::SEARCH_TRACK  => self::API_ENDPOINT . "search",
    Platform::SEARCH_ALBUM  => self::API_ENDPOINT . "search",
   // Platform::SEARCH_ARTIST => self::API_ENDPOINT . "search" 
  };
  protected ImmMap<int,?string> $terms = ImmMap {
    Platform::LOOKUP_TRACK  => null,
    Platform::LOOKUP_ALBUM  => null,
    Platform::LOOKUP_ARTIST => null,
    Platform::SEARCH_TRACK  => "q",
    Platform::SEARCH_ALBUM  => "q",
   // Platform::SEARCH_ARTIST => "q" 
  };
  protected ImmMap<int,ImmMap<string,mixed>> $options = ImmMap {
    Platform::LOOKUP_TRACK  => ImmMap {},
    Platform::LOOKUP_ALBUM  => ImmMap {},
    Platform::LOOKUP_ARTIST => ImmMap {},
    Platform::SEARCH_TRACK  => ImmMap { "type" => "track", "limit" => Platform::LIMIT },
    Platform::SEARCH_ALBUM  => ImmMap { "type" => "album", "limit" => Platform::LIMIT },
   // Platform::SEARCH_ARTIST => ImmMap { "type" => "artist", "limit" => Platform::LIMIT } 
  };

  // http://on.beatsmusic.com/albums/al8992411/tracks/tr8992441
  // http://on.beatsmusic.com/artists/ar27304
  // http://on.beatsmusic.com/albums/al6960443
  const string REGEX_BEATS_TRACK = "/albums\/(?P<album_id>al".Platform::REGEX_NUMERIC_ID.")\/tracks\/(?P<track_id>tr".Platform::REGEX_NUMERIC_ID.")$/";
  const string REGEX_BEATS_ALBUM = "/albums\/(?P<album_id>al".Platform::REGEX_NUMERIC_ID.")$/";
  const string REGEX_BEATS_ARTIST = "/artists\/(?P<artist_id>ar".Platform::REGEX_NUMERIC_ID.")$/";

  public function hasPermalink(string $permalink): bool
  {
    return (strpos($permalink, "beatsmusic.com") !== false);
  }

  private function getCoverUrlFromAlbumId(string $album_id): string
  {
    // http://mn.ec.cdn.beatsmusic.com/albums/008/992/411/m.jpeg
    // s = small, m = medium, b = large, g = large
    $padded_id = str_pad(substr($album_id,2), 9, "0", STR_PAD_LEFT);
    return sprintf("http://mn.ec.cdn.beatsmusic.com/albums/%s/%s/%s/g.jpeg", substr($padded_id,0,3), substr($padded_id,3,3), substr($padded_id,-3));
  }

  public function expandPermalink(string $permalink, int $mode): ?PlatformResult
  {

    $musical_entity = null;
    $query_words = Vector {$permalink};

    $match = Map {};

    if (preg_match(self::REGEX_BEATS_TRACK, $permalink, $match)) {

      $response = $this->fetchSync(Platform::LOOKUP_TRACK, $match['track_id']);

      if ($response === null || !property_exists($response, 'data')) { return null; }

      $musical_entity = new TrackEntity($response->data->title, new AlbumEntity($response->data->refs->album->display, $response->data->artist_display_name, $this->getCoverUrlFromAlbumId($match['album_id']))); 
      $musical_entity->addLink($permalink);

      $query_words = Vector {$response->data->artist_display_name, $response->data->title};
      
    } else if (preg_match(self::REGEX_BEATS_ALBUM, $permalink, $match)) {
     
      $response = $this->fetchSync(Platform::LOOKUP_ALBUM, $match['album_id']);

      if ($response === null || property_exists($response, 'error')) { return null; }

      $musical_entity = new AlbumEntity($response->title, $response->artist->name, $response->cover);
      $musical_entity->addLink($permalink);

      $query_words = Vector {$response->artist->name, $response->title};
      
    } else if (preg_match(self::REGEX_BEATS_ARTIST, $permalink, $match)) {

      $response = $this->fetchSync(Platform::LOOKUP_ARTIST, $match['artist_id']);

      if ($response !== null && !property_exists($response, 'error')) {
        $query_words = Vector {$response->name};
      }

    }
  
    // Consolidate results
    $metadata = Map {"query_words" => $query_words};

    if ($musical_entity !== null) {
      $metadata->add(Pair {"platform", $this->getName()});
    }

    return new PlatformResult($metadata, $musical_entity);
      
  }

  public async function search(int $type, string $query, int $limit, int $mode): Awaitable<?Vector<PlatformResult>>
  {
    $response = await $this->fetch($type, $query);

    if ($response === null || intval($response->info->total) === 0) {
      return null;
    }

    // We actually don't pass the limit to the fetch() 
    // request since it's not really useful, in fact
    $length = min(intval($response->info->total), $limit?$limit:Platform::LIMIT);
    
    $musical_entities = Vector {};

    // Normalizing each track found
    for($i=0; $i<$length; $i++){
    
      $current_item = $response->data[$i];

      if ($type === Platform::SEARCH_TRACK) {

        $musical_entity = new TrackEntity($current_item->display, new AlbumEntity($current_item->related->display, $current_item->detail, $this->getCoverUrlFromAlbumId($current_item->related->id))); 
        $musical_entity->addLink(sprintf("http://on.beatsmusic.com/albums/%s/tracks/%s", $current_item->related->id, $current_item->id));
             
      } else /*if ($type === Platform::SEARCH_ALBUM)*/ {

        $musical_entity = new AlbumEntity($current_item->display, $current_item->detail, $this->getCoverUrlFromAlbumId($current_item->id)); 
        $musical_entity->addLink(sprintf("http://on.beatsmusic.com/albums/%s", $current_item->id));
      
      }
      
      $musical_entities->add(new PlatformResult(Map {"score" => Utils::indexScore($i)}, $musical_entity));

    }
    
    return $musical_entities;
  }
}