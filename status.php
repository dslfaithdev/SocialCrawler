<pre>
<?
echo system('for A in posts_files/posts.txt.*
do FILE=${A##*\.}
  echo -n "$FILE: "
  TOTAL=`awk \'//{n++}; END {printf n/2}\' $A`
  DONE=`find outputs/ -name ${FILE}_* |wc -l 2>/dev/null`
  echo "${TOTAL} ${DONE}" | \
    awk \'{ORS=" "; s=($2/$1)
      print $2 "/" $1 " => " s*100  "% "
      if (s >= 1) exit 0; exit 1}\' && \
   test \! -f ${FILE}.tgz && \
   find ./outputs/ -name "${FILE}_*" | \
     tar -czf ${FILE}.tgz -T -
   if [ -f ${FILE}.tgz ]; then
     echo "<a href=\"${FILE}.tgz\">${FILE}.tgz</a>"
   else
    echo ""
  fi
done');
flush();
?>
</pre>
