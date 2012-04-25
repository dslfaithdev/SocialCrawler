<pre>
<?
echo shell_exec('for A in posts_files/posts.txt.*
do FILE=${A##*\.}
  echo -n "$FILE: "
  TOTAL=`awk \'//{n++}; END {printf n/2+1}\' $A`
  DONE=`cat  config/lastCount_${FILE}_*.txt 2>/dev/null`
  echo "${TOTAL} ${DONE}" | \
    awk \'{ORS=" "; s=($2/$1)
      print $2 "/" $1 " => " s*100  "% "
      if (s == 1) exit 0; printf "\n"; exit 1}\' && \
   test \! -f ${FILE}.tgz && \
   find ./outputs/ -name "${FILE}_*" | \
     tar -czf ${FILE}.tgz -T -
   test -f ${FILE}.tgz && echo "<a href=\"${FILE}.tgz\">${FILE}.tgz</a>"
done');
flush();
?>
</pre>
