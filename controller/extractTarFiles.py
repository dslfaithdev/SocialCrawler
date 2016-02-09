import tarfile
import os
from glob import glob
import sys

with tarfile.open(sys.argv[1]) as tar:
    for f in tar:
        name = f.name[:-5]
        if os.path.isfile(name + "#001.json"):
            index = len(glob(name + "*")) + 1
            newName = name + "#%03d.json" % index
        else:
            newName = name + "#001.json"
        tar.extract(f)
        os.rename(f.name, newName)
        print newName
