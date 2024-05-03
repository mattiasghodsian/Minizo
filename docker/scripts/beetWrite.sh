#!/bin/bash

if [ "$#" -ne 2 ]; then
    echo "Usage: ./beetWrite.sh [file] [releaseID]"
    exit 1
fi

file="$1"
releaseID="$2"

command="beet import --write -S $releaseID"

echo "A" | ${command} "${file}"