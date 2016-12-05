<?php
namespace MailPoet\Newsletter\Shortcodes\Categories;

use MailPoet\Models\Subscriber as SubscriberModel;
use MailPoet\Models\SubscriberCustomField;

if(!defined('ABSPATH')) exit;
require_once(ABSPATH . 'wp-includes/pluggable.php');

class Subscriber {
  static function process(
    $action,
    $default_value,
    $newsletter = false,
    $subscriber
  ) {
    if($subscriber !== false && !is_object($subscriber)) return false;
    switch($action) {
      case 'firstname':
        return ($subscriber) ? $subscriber->first_name : $default_value;
      case 'lastname':
        return ($subscriber) ? $subscriber->last_name : $default_value;
      case 'email':
        return ($subscriber) ? $subscriber->email : false;
      case 'displayname':
        if($subscriber && $subscriber->wp_user_id) {
          $wp_user = get_userdata($subscriber->wp_user_id);
          return $wp_user->user_login;
        }
        return $default_value;
      case 'count':
        return SubscriberModel::filter('subscribed')
          ->count();
      default:
        if(preg_match('/cf_(\d+)/', $action, $custom_field) &&
          !empty($subscriber->id)
        ) {
          $custom_field = SubscriberCustomField
            ::where('subscriber_id', $subscriber->id)
            ->where('custom_field_id', $custom_field[1])
            ->findOne();
          return ($custom_field) ? $custom_field->value : false;
        }
        return false;
    }
  }
}