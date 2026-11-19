#!/bin/bash

rm -rf srv/api/data
rm -rf srv/api/vendor
find srv/api/public -maxdepth 1 ! -name '.' ! -name '..' ! -name '.gitignore' -exec rm -rf {} +

rm -rf srv/ui/dist
rm -rf srv/ui/node_modules
