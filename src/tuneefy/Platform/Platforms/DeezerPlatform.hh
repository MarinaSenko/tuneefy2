<?hh // strict

namespace tuneefy\Platform\Platforms;

use tuneefy\Platform\Platform,
    tuneefy\Platform\WebStreamingPlatformInterface,
    tuneefy\MusicalEntity\MusicalEntity,
    tuneefy\MusicalEntity\Entities\TrackEntity,
    tuneefy\MusicalEntity\Entities\AlbumEntity,
    tuneefy\Utils\Utils;

class DeezerPlatform extends Platform implements WebStreamingPlatformInterface
{
  
  const string NAME = "Deezer";
  const string TAG = "deezer";
  const string COLOR = "181818";

  const string API_ENDPOINT = "https://api.deezer.com/";
  const string API_METHOD = Platform::METHOD_GET;
  const bool NEEDS_OAUTH = false;

  protected ImmMap<int,?string> $endpoints = ImmMap {
    Platform::LOOKUP_TRACK  => self::API_ENDPOINT . "track/%s",
    Platform::LOOKUP_ALBUM  => self::API_ENDPOINT . "album/%s",
    Platform::LOOKUP_ARTIST => self::API_ENDPOINT . "artist/%s",
    Platform::SEARCH_TRACK  => self::API_ENDPOINT . "search/track",
    Platform::SEARCH_ALBUM  => self::API_ENDPOINT . "search/album",
   // Platform::SEARCH_ARTIST => self::API_ENDPOINT . "search/artist" 
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
    Platform::SEARCH_TRACK  => ImmMap { "nb_items" => Platform::LIMIT },
    Platform::SEARCH_ALBUM  => ImmMap { "nb_items" => Platform::LIMIT },
   // Platform::SEARCH_ARTIST => ImmMap { "nb_items" => Platform::LIMIT } 
  };

  // NOT VALID ANYMORE http://www.deezer.com/listen-10236179
  // NOT VALID ANYMORE http://www.deezer.com/music/track/10240179
  // http://www.deezer.com/track/10444623
  const string REGEX_DEEZER_TRACK = "/(listen-|music\/track\/|\/track\/)(?P<track_id>".Platform::REGEX_NUMERIC_ID.")$/";
  // NOT VALID ANYMORE http://www.deezer.com/fr/music/rjd2/deadringer-144183
  // http://www.deezer.com/fr/album/955330
  const string REGEX_DEEZER_ALBUM = "/\/album\/(?P<album_id>".Platform::REGEX_NUMERIC_ID.")$/";
  // http://www.deezer.com/fr/music/radiohead
  // http://www.deezer.com/fr/artist/16948
  const string REGEX_DEEZER_ARTIST = "/\/(music|artist)\/(?P<artist_id>".Platform::REGEX_FULLSTRING.")$/";

  public function hasPermalink(string $permalink): bool
  {
    return (strpos($permalink, "deezer.") !== false);
  }

  public function expandPermalink(string $permalink): ?Map<string,mixed>
  {

    $musical_entity = null;
    $query_words = Vector {$permalink};

    $match = Map {};

    if (preg_match(self::REGEX_DEEZER_TRACK, $permalink, $match)) {

      $response = $this->fetch(Platform::LOOKUP_TRACK, $match['track_id']);

      if ($response === null) { return null; }

      $musical_entity = new TrackEntity($response->title, new AlbumEntity($response->album->title, $response->artist->name, $response->album->cover)); 
      $musical_entity->addLink($permalink);
        
      // We modify the query
      $query_words = Vector {$response->artist->name, $response->title};
      
    } else if (preg_match(self::REGEX_DEEZER_ALBUM, $permalink, $match)) {
     
      $response = $this->fetch(Platform::LOOKUP_ALBUM, $match['album_id']);

      if ($response === null) { return null; }

      $musical_entity = new AlbumEntity($response->title, $response->artist->name, $response->cover);
      $musical_entity->addLink($permalink);

      // We just modify the query
      $query_words = Vector {$response->artist->name, $response->title};
      
    } else if (preg_match(self::REGEX_DEEZER_ARTIST, $permalink, $match)) {

      $response = $this->fetch(Platform::LOOKUP_ARTIST, $match['artist_id']);

      if ($response === null || $response->error->code == 800) { // "no data"
        $query_words = Vector {$match['artist_id']};
      } else {
        $query_words = Vector {$response->name};
      }

    }
  
    // Consolidate results
    $result = Map {"query_words" => $query_words};

    if ($musical_entity !== null) {
      $result->add(Pair {"musical_entity", $musical_entity->toMap()});
      $result->add(Pair {"platform", $this->getName()});
    }

    return $result;
      
  }

  public function search(int $type, string $query, int $limit): ?Vector<Map<string,mixed>>
  {
  
    $response = $this->fetch($type, $query);

    if ($response === null || intval($response->total) === 0) {
      return null;
    }

    // We actually don't pass the limit to the fetch() 
    // request since it's not really useful, in fact
    $length = min(count($response->data), $limit?$limit:Platform::LIMIT);
    
    $musical_entities = Vector {};

    // Normalizing each track found
    for($i=0; $i<$length; $i++){
    
      $current_item = $response->data[$i];

      if ($type === Platform::SEARCH_TRACK) {
        
        if (property_exists($current_item->album, 'cover')) {
          $picture = $current_item->album->cover;
        } else { 
          $picture = $current_item->artist->picture;
        }
        
        $musical_entity = new TrackEntity($current_item->title, new AlbumEntity($current_item->album->title, $current_item->artist->name, $picture)); 
        $musical_entity->addLink($current_item->link);
             
      } else /*if ($type === Platform::SEARCH_ALBUM)*/ {

        if (property_exists($current_item, 'cover')) {
          $picture = $current_item->cover;
        } else { 
          $picture = $current_item->artist->picture;
        }

        $musical_entity = new AlbumEntity($current_item->title, $current_item->artist->name, $picture); 
        $musical_entity->addLink($current_item->link);
      
      }
      
      $musical_entities->add(Map { "musical_entity" => $musical_entity->toMap(), "score" => Utils::indexScore($i)});

    }
    
    return $musical_entities;
    
  }
}
