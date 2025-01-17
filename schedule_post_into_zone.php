<?php
/*
Plugin Name: Schedule post into Zoninator zone
Depends: Zone Manager (Zoninator)
Description: Inserts scheduled posts into the Zoninator zones on publication
Author: Vladimir Smotesko, Boyle Software
Version: 0.1
Author URI: http://smotesko.com

Copyright 2013 Vladimir Smotesko, Boyle Software

*/

if (!class_exists('Schedule_Zoninator')):

class Schedule_Zoninator {
  private $zoninator; // instance of Zoninator plugin
  private $zone_posts = array(); // local cache variable
  
  const nonce_field = 'schedule_zoninator_nonce';
  const zone_id_key = 'schedule_zone_zone_id';
  const position_key = 'schedule_zone_position';

  public function __construct() {
    add_action('init', array($this, 'check_zoninator'));
  }
  
  public function check_zoninator() {
    global $zoninator;
    if (
      isset($zoninator) && 
      $zoninator instanceof Zoninator
    ) {
      $this->zoninator = $zoninator;
      $this->init_plugin();
    } else {
      add_action('admin_notices', array($this, 'zoninator_not_found_notice'));
    }
  }
  
  private function init_plugin() {
    add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
    add_action('save_post', array($this, 'save_post'));
    add_action('publish_future_post', array($this, 'publish_future_post'));
  }
  
  public function zoninator_not_found_notice() {
    ?>
      <div class="error">
        <p>
          <?php _e(
            'Zoninator plugin is not installed or not activated! '.
            'Zoninator schedule will not work.'
            ); 
          ?>
        </p>
      </div>
    <?php
  }
  
  public function add_meta_boxes() {
    add_meta_box(
      'schedule_zone',
      __('Schedule post into zone'),
      array($this, 'metaboxes_zone'),
      'post',
      'normal'
    );
  }
  
  public function metaboxes_zone($post) {
    $available_zones = $this->zoninator->get_zones();
    $selected_zone_id = get_metadata(
      'post', 
      $post->ID, 
      self::zone_id_key, 
      TRUE
    );
    $current_position = get_metadata(
      'post', 
      $post->ID, 
      self::position_key, 
      TRUE
    );
    
    wp_nonce_field(self::nonce_field, self::nonce_field);
    ?>
    <p>
      <label for="<?php echo self::zone_id_key; ?>"><?php _e('Zone'); ?>
        </label><br/>
      <select name="<?php echo self::zone_id_key; ?>" 
        id="<?php echo self::zone_id_key; ?>">
        <option value=""><?php _e('Select zone'); ?></option>
        <?php foreach ($available_zones as $available_zone): ?>
        <option value="<?php echo $available_zone->term_id; ?>"
        <?php 
          echo ($selected_zone_id == $available_zone->term_id)? 
            'selected="selected"' : 
            ''
          ; 
        ?>
        >
          <?php echo $available_zone->name; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </p>
    <p>
      <label for="<?php echo self::position_key; ?>"><?php _e('Position'); ?>
        </label><br/>
      <input type="number" 
        value="<?php echo $current_position; ?>" 
        name="<?php echo self::position_key; ?>" 
        id="<?php echo self::position_key; ?>" />
    </p>
    <?php if (
      ($selected_zone_id && !$current_position) ||
      (!$selected_zone_id && $current_position)
    ): ?>
    <p>
      <?php _e('You have selected only one setting. '.
        'Post will not be put into zone without both settings.'); ?>
    </p>
    <?php endif; ?>
    <p class="description">
      <?php _e('Your scheduled post will be put into the selected zone.'); ?>
    </p>
    <p class="description">
      <?php _e('Position is a number, usually from 1 to 10.'); ?>
    </p>
    <?php
  }
  
  public function save_post($post_id) {
    if (wp_is_post_revision($post_id))
      return;
    if (
      !isset($_POST[self::nonce_field]) || 
      !wp_verify_nonce($_POST[self::nonce_field], self::nonce_field) 
    )
      return;
    if(!current_user_can('edit_post', $post_id))
			return;
    
    if (isset($_POST[self::zone_id_key]) && $_POST[self::zone_id_key]) {
      $zone_id = absint($_POST[self::zone_id_key]);
      if ($this->zoninator->zone_exists($zone_id)) 
        update_metadata('post', $post_id, self::zone_id_key, $zone_id);
    } else 
      delete_metadata('post', $post_id, self::zone_id_key);
    
    if (isset($_POST[self::position_key]) && $_POST[self::position_key]) {
      $position = absint($_POST[self::position_key]);
      if ($position > 0) 
        update_metadata('post', $post_id, self::position_key, $position);
    } else 
      delete_metadata('post', $post_id, self::position_key);
  }
  
  public function publish_future_post($post_id) {
    $zone_id = intval(get_metadata('post', $post_id, self::zone_id_key, TRUE));
    $position= intval(get_metadata('post', $post_id, self::position_key, TRUE));
    
    delete_metadata('post', $post_id, self::zone_id_key);
    delete_metadata('post', $post_id, self::position_key);
   
    if (!$zone_id) {
      return;
    }
    if (!$position) {
      return;
    }
    if (!$this->zone_exists($zone_id)) {
      return;
    }

    $posts = $this->get_zone_posts($zone_id);
    if (count($posts) < $position) {
      $posts[] = $post_id;
    } else {
      array_splice($posts, ($position-1), 0, array($post_id));
    }
    $this->update_zone_posts($zone_id, $posts);
  }
  
  /*
   * I had to create the following four functions because the Zoninator's ones 
   * are not working during the publish_future_post hook execution:
   * zone_exists()
   * get_zone_posts()
   * update_zone_posts()
   * clear_zone_posts()
   */
  
  /**
   * Check whether the Zoninator zone exists
   * @param int $zone_id
   * @return boolean
   */
  private function zone_exists($zone_id) {
    global $wpdb;
    return ($wpdb->get_var(
      "SELECT COUNT(term_taxonomy_id)
      FROM {$wpdb->term_taxonomy}
      WHERE term_id = $zone_id
      AND taxonomy = '{$this->zoninator->zone_taxonomy}'"
    ) > 0);
  }
  
  /**
   * Get the Zoninator zone's posts
   * @param int $zone_id
   * @return array posts IDs
   */
  private function get_zone_posts($zone_id) {
    if (
      isset($this->zone_posts[$zone_id]) &&
      is_array($this->zone_posts[$zone_id])
    ) {
      return $this->zone_posts[$zone_id];
    }
    global $wpdb; 
    $this->zone_posts[$zone_id] = $wpdb->get_col(
      "SELECT {$wpdb->postmeta}.post_id
      FROM {$wpdb->postmeta}
      WHERE {$wpdb->postmeta}.meta_key = 
        '{$this->zoninator->zone_meta_prefix}$zone_id'
      ORDER BY {$wpdb->postmeta}.meta_value ASC"
    );
    return $this->zone_posts[$zone_id];
  }
  
  /**
   * Overwrite zone posts
   * @param int $zone_id
   * @param array $posts
   * @return boolean
   */
  private function update_zone_posts($zone_id, $posts) {
    $this->clear_zone_posts($zone_id);
    foreach ($posts as $n => $post_id) {
      update_metadata(
        'post', 
        $post_id, 
        $this->zoninator->zone_meta_prefix.$zone_id, 
        $n+1
      );
    }
    clean_term_cache($zone_id, $this->zoninator->zone_taxonomy);
    return TRUE;
  }
  
  /**
   * Clear zone from posts
   * @param int $zone_id
   * @return boolean
   */
  private function clear_zone_posts($zone_id) {
    foreach($this->get_zone_posts($zone_id) as $post_id) {
      delete_metadata(
        'post', 
        $post_id, 
        $this->zoninator->zone_meta_prefix.$zone_id
      );
    }
    $this->zone_posts[$zone_id] = NULL;
    return TRUE;
  }

}

global $schedule_zoninator;
$schedule_zoninator = new Schedule_Zoninator();

endif;
