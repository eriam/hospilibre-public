#!/bin/sh

BASH_PATH=$(dirname $0)
. $BASH_PATH/utils.sh

########
# Hospilibre request launcher
########

announce_script "Hospilibre lite request launcher"

if [ "$#" -lt 1 ]
then 
  echo "Usage: $0 <token> \[-u root_url\] \[-t times\] \[-d delay\] \[-f file\] \[-T delay\]"
  echo "  <token> is the user token"
  echo "  [-u root_url] is root url for hospilibre, default value is http://localhost/hospilibre/"
  echo "  [-t <times>] is the number of repetition, ie 4"
  echo "  [-d <delay>] is the time between each repetition, ie 2"
  echo "  [-f <file>] is the file for the output, ie log.txt"
  echo "  [-T <timeout>] is the time before stopping wget (server not responding or other problems)"
  exit 1
fi

times=1
delay=1
timeout=""
root_url="http://localhost/hospilibre"

args=$(getopt u:t:d:f:T: $*)
if [ $? != 0 ] ; then
  echo "Invalid argument. Check your command line"; exit 0;
fi

set -- $args
for i; do
  case "$i" in
    -u) root_url=$2; shift 2;;
    -t) times=$2; shift 2;;
    -d) delay=$2; shift 2;;
    -f) file="-O $2"; shift 2;;
    -T) timeout="-T $2"; shift 2;;
    --) shift; break ;;
  esac
done

echo ${root_url}

token="token=$1"

url="$root_url/index.php?$token"

# Make hospilibre path
HOSPILIBREPATH=/var/log/hospilibre
force_dir $HOSPILIBREPATH

log=$HOSPILIBREPATH/jobs.log
force_file $log

hospilibre_request() 
{
   wget $url\
         -qO-\
        --append-output="$log"\
        --force-directories\
        --no-check-certificate\
        $timeout\
        $file
   check_errs $? "Failed to request to Hospilibre" "Hospilibre requested!"   
   echo "wget URL : $url."
}

if [ $times -gt 1 ]
then
  while [ $times -gt 0 ]
  do
    times=$(($times - 1))
    hospilibre_request &
    sleep $delay
  done
else
  hospilibre_request
fi