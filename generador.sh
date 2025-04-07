#!/bin/bash
OUTDIR="./documentos"
mkdir -p "$OUTDIR"

DOCS=("pequenosTraviesos" "hojaPersonaje")

for DOC in "${DOCS[@]}"; do
  echo "Procesando $DOC..."

  # Generar HTML (para PDF)
  php ./generateDocs.php "$DOC"

  if [ ! -f "$DOC.html" ]; then
    echo "No se pudo generar $DOC.html"
    continue
  fi

  # Generar PDF desde HTML con WeasyPrint
  # ~/.local/bin/weasyprint "$DOC.html" "$OUTDIR/$DOC.pdf"
  # echo "PDF generado con WeasyPrint: $OUTDIR/$DOC.pdf"
  # Generar PDF desde HTML con chromium
  chromium --headless --no-sandbox --disable-gpu \
    --print-to-pdf="./temp.pdf" \
    "file://$(pwd)/$DOC.html"
  # Aplicar metadatos si existe archivo .txt
  if [ -f "$DOC.txt" ]; then
    pdftk './temp.pdf' update_info_utf8 "./$DOC.txt" output "$OUTDIR/$DOC.pdf" compress
  else
    mv ./temp.pdf "$OUTDIR/$DOC.pdf"
  fi
  # Generar EPUB desde el .md directamente (mejor TOC)
  pandoc "$DOC.md" \
    --toc \
    --toc-depth=2 \
    --metadata title="$(echo "$DOC" | sed -E 's/(^|-)([a-z])/\U\2/g')" \
    --metadata author="Jesús Pavón Abián" \
    --title-prefix="" \
    -o "$OUTDIR/$DOC.epub"

  echo "EPUB generado desde Markdown: $OUTDIR/$DOC.epub"

  # Limpieza
  rm -f "$DOC.html"
  rm -f ./temp.pdf
done

echo "Todos los PDF y EPUB están en $OUTDIR/"
