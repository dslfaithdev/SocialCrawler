#!/bin/sh

SQL="select id,name from (select page.id as id,name, count(case when status = 'done' then 1.0 end)*100.0/count(*) as done from page join post where post.page_id=page.id group by page.id) where done = 100;"

for PAGE in `sqlite3 database.db "$SQL"`; do
  TAR=${PAGE#*\|}
  if [ -f "$TAR.tgz" ]; then
    echo "- $TAR.tgz already exist, skipping (to re-generage issue rm -f \"$TAR.tgz\")."
    continue
  fi
  echo "+ Creating ${TAR}.tgz"
  touch "${TAR}"
  SQL="SELECT post.id,page.name || '_' || substr(('00000000'||seq),,-8,8) || '_' || substr(date,0,13)||'_'|| post_id ||'.json'  from post join page on post.page_id=page.id WHERE page.id=${PAGE%\|*};"
  #sqlite3 database.db "$SQL"

  for RES in `sqlite3 database.db "$SQL"`; do
    ID=${RES%\|*}
    FILE=${RES#*\|}
    sqlite3 database.db "SELECT data FROM post WHERE id=$ID LIMIT 1" | base64 -d -i | gzip -d > $FILE
    tar -uf "${TAR}" $FILE
    rm $FILE
  done
  gzip -f -S .tgz "${TAR}"
done
