#!/bin/sh

BASH_PATH=$(dirname $0)
. $BASH_PATH/utils.sh

########
# Backups hospilibre database on a daily basis
########

announce_script "Hospilibre daily backup"

if [ "$#" -lt 2 ]
then
  sh $BASH_PATH/baseBackup.sh hotcopy mbadmin adminmb hospilibre /var/backup
else
  user=$1
  pass=$2
  sh $BASH_PATH/baseBackup.sh hotcopy $user $pass hospilibre /var/backup
fi