#!/usr/local/bin/bash

for T in phar/*tar; do printf "%s%-30s%8s%6s %s\n" $(stat -f %m $T) ${T:5:30} $(tar -tf $T 2>/dev/null|wc -l) $(ls -lh $T|awk '{print $5}') "$(stat -f %Sm $T)";done|sort -n|cut -c11-

  echo 
  echo 
  ls -rthl phar
