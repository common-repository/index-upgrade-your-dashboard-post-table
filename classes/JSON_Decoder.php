<?php
if( !class_exists( 'BLT_JSON_Decode' ) ) {
    class BLT_JSON_Decode {
        protected static $messages = array(
            JSON_ERROR_NONE => 'No error has occurred',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'A Control character error occurred. Your JSON file may have possibly been incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error (you may have an extra comma or are using single quotes in place of double quotes)',
            JSON_ERROR_UTF8 => 'You have malformed UTF-8 characters. They may have possibly been incorrectly encoded'
        );

        public static function decode($json, $assoc = false) {
            $result = json_decode($json, $assoc);

            if($result) {
                return $result;
            }

            print_r(static::$messages[json_last_error()]);

            die();
        }
    }
}