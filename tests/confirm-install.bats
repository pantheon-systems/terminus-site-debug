#!/usr/bin/env bats

#
# confirm-install.bats
#
# Ensure that Terminus and the Composer plugin have been installed correctly
#

@test "confirm terminus version" {
  terminus --version
}

@test "get help on plugin command" {
  run terminus help logs:parse
  [[ $output == *logs:parse [options] [--] <site_env>* ]]
  [ "$status" -eq 0 ]
}
