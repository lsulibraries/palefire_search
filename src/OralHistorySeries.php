<?php

namespace Drupal\palefire_search;

/**
 * The oral history series, by the folder their abstracts are kept in.
 *
 * Each abstract is a PDF at /sites/all/files/oralhistory/abstracts/<folder>/,
 * and the Catalog's record links it, so the folder is what ties a series'
 * interviews together in both places. The Catalog writes series names its own
 * way ("Brusly centennial oral history project", or none at all), so the name
 * here is the one the site shows, as field_series has it.
 *
 * To add a series: add its folder and name here, then run
 * scripts/import_oral_histories.php (a dry run first) to add its interviews.
 */
final class OralHistorySeries {

  /**
   * Folder => series name.
   */
  public const FOLDERS = [
    'acadian' => 'Acadian Handicraft Project',
    'adriennelacour' => 'Adrienne LaCour - Four Corners Series',
    'americansvietnam' => 'Americans in Vietnam',
    'brblues' => 'Baton Rouge Blues',
    'brhistory' => 'Baton Rouge History and Culture',
    'brinsurance' => 'Baton Rouge Insurance Industry',
    'brlgbtq' => "Baton Rouge's LGBTQ Community: Then and Now",
    'bayoulafourche' => 'Bayou Lafourche Oral History Project',
    'britishvoices' => 'British Voices from South Asia',
    'brusly' => 'Brusly Centennial Oral History Project',
    'civilrights' => 'Civil Rights Series',
    'gillis' => 'Gillis Long Series',
    'historycoopexten' => 'History of Louisiana Cooperative Extension Services',
    'hurricanebetsy' => 'Hurricane Betsy Survivor Stories',
    'lasg' => 'Louisiana Sea Grant Coastal Change Oral Histories Project',
    'lsms' => 'Louisiana State Medical Society',
    'mckinley' => 'McKinley High School Oral History Project',
    'michaelmulhern' => 'Michael Mulhern Centenarians Series',
    'military' => 'Military Series',
    'mossville' => 'Mossville History Project',
    'ourladyofthelake' => 'Our Lady of the Lake, "Under Pressure: A Louisiana Hospital\'s COVID-19 Experience"',
    'perique' => 'Perique Tobacco',
    'political' => 'Political Series',
    'robertcox' => 'Robert Ray Cox Series',
    'suehebert' => 'Sue Hebert Series',
    'university' => 'University History',
    'various' => 'Various Topics',
    'voicesinthestorm' => 'Voices in the Storm: Hurricane Katrina Survivors in Pointe Coupee Parish',
    'voa' => 'Volunteers of America',
    'wbrremembrance' => 'West Baton Rouge Parish Remembrance',
    'WJBO' => 'WJBO Radio Interviews',
    'womenscouncil' => "Women's Council of Greater Baton Rouge",
    // In the Catalog, not yet on the site (2026-11-11). Names as the Catalog
    // has them, without its "series" at the end.
    'sicilian' => 'Sicilian-Italian Oral History Project',
    'gu272' => '272 Slaves: Discovering Louisiana and Georgetown’s Past',
    'reillycenterwomeninpolitics' => 'Women in Politics',
    'wlem' => "Women in Louisiana's Environmental Movement",
    'jazzfest' => 'The Jazz and Heritage Festival and Foundation Class Project: 2014',
    'nonwck' => 'New Orleans New Wave Carnival Krewes',
    'lgbtarchivesprojectoflouisiana' => 'LGBT+ Archives Project of Louisiana',
  ];

  /**
   * An abstract's folder, from its address or site path, or NULL.
   */
  public static function folderOf(string $url): ?string {
    return preg_match('#/oralhistory/abstracts/([^/]+)/[^/]+\.pdf$#i', $url, $match) ? $match[1] : NULL;
  }

}
