#!/bin/sh

#Use command like:
SQL_CMD='mysql --user USER --password=PASSWORD --disable-column-names -B DATABASE'

OUTPUT_DIR='./done'


mkdir -p -- "$OUTPUT_DIR"
cd $OUTPUT_DIR

SQL="SELECT id,name FROM (SELECT page.id AS id,name, COUNT(CASE WHEN status = 'done' THEN 1.0 END)*100.0/COUNT(*) AS done from page join post where post.page_id=page.id group by page.id) AS T where done = 100;"

for PAGE in `echo $SQL | $SQL_CMD | sed 's/\t/|/g'`; do
  TAR=${PAGE#*\|}
  if [ -f "$TAR.tgz" ]; then
    echo "- $TAR.tgz already exist, skipping (to re-generage issue rm -f \"$TAR.tgz\")."
    continue
  fi
  echo "+ Creating ${TAR}.tgz"
  touch "${TAR}"
  SQL="SELECT post.id,CONCAT(page.name , '_' , substr(CONCAT('00000000',seq),-8,8) , '_' , substr(date,1,13),'_', post_id ,'.json')  from post join page on post.page_id=page.id WHERE page.id=${PAGE%\|*};"
  ERROR_ID=""
  for RES in `echo $SQL | $SQL_CMD | sed 's/\t/|/g'`; do
    ID=${RES%\|*}
    FILE=${RES#*\|}
    echo "SELECT data FROM post WHERE id=$ID LIMIT 1"| $SQL_CMD | base64 -d -i 2>/dev/null | gzip -d 2>/dev/null > $FILE
    if [ $? -ne 0 ]; then
      ERROR_ID="$ID, $ERROR_ID"
    else
      tar -uf "${TAR}" $FILE
    fi
    rm $FILE
  done
  if [ -n "$ERROR_ID" ]; then
    echo "UPDATE post SET status='pulled' WHERE id IN (${ERROR_ID%, })" | $SQL_CMD
    echo "Errors occored in: $ERROR_ID"
  else
    gzip -f -S .tgz "${TAR}"
  fi
done
