#!/bin/bash
set -e

APPID="137452509751132"
APPSEC="K-KOBK0PsP0ah5_sfx1jnwhCel8"
POSTS="332036720237405,332036720237405_332039370237140"

sed -i "s/'APPID', *\"\"/'APPID',\"$APPID\"/g; s/'APPSEC', *\"\"/'APPSEC',\"$APPSEC\"/g" ./config/config.php


php agent.php token=${APPID}\|${APPSEC} posts=${POSTS}

cat *.json
