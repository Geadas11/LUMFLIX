from flask import Flask, request, jsonify
from thepiratebay_api import TorrentClient
import traceback

app = Flask(__name__)

HOST = "127.0.0.1"
PORT = 8001


def criar_cliente():
    """
    Procura automaticamente um mirror do The Pirate Bay
    que esteja disponível e cria o cliente.
    """

    print("[TPB] A procurar mirrors disponíveis...")

    # Cliente temporário apenas para descobrir os mirrors
    base_client = TorrentClient(
        verify=False,
        timeout=30,
        headers={
            "User-Agent": "Mozilla/5.0"
        }
    )

    mirrors = base_client.mirrors()

    alive = mirrors.alive

    if not alive:
        raise Exception("Nenhum mirror do The Pirate Bay está disponível.")

    print("[TPB] Mirrors disponíveis:")

    for mirror in alive:
        print(f"      {mirror.url}")

    # Usar o primeiro mirror disponível
    mirror = alive[0]

    print(f"[TPB] A utilizar: {mirror.url}")

    return TorrentClient(
        url=mirror.url,
        verify=False,
        timeout=30,
        headers={
            "User-Agent": "Mozilla/5.0"
        }
    )


def procurar_torrents(query):
    """
    Procura torrents de filmes e obtém os respetivos
    magnet links.
    """

    client = criar_cliente()

    try:
        resultados = client.search(
            query,
            category=TorrentClient.Category.Video.HD_MOVIES,
            sort_by=TorrentClient.SortBy.SEEDERS_DESC
        )

        torrents = []

        print(
            f"[TPB] Encontrados {len(resultados.torrents)} resultados."
        )

        # Limitar aos 10 primeiros
        for torrent in resultados.torrents[:10]:

            try:
                detalhes = client.detail(torrent.torrent_id)

                torrents.append({
                    "id": str(torrent.torrent_id),
                    "title": detalhes.title,
                    "magnet": detalhes.magnet_link,
                    "size": str(detalhes.size),
                    "seeders": detalhes.seeders,
                    "leechers": detalhes.leechers,
                    "trusted": detalhes.is_trusted,
                    "vip": detalhes.is_vip,
                    "info_hash": detalhes.info_hash,
                    "num_files": detalhes.num_files
                })

                print(
                    f"[TPB] {detalhes.title} "
                    f"| Seeders: {detalhes.seeders}"
                )

            except Exception as e:
                print(
                    f"[TPB] Erro ao obter detalhes "
                    f"do torrent {torrent.torrent_id}: {e}"
                )

        return torrents

    finally:
        client.close()


@app.route("/health", methods=["GET"])
def health():
    """
    Verifica se a API está ligada.
    """

    return jsonify({
        "status": "ok",
        "service": "lumflix-torrent-api"
    })


@app.route("/search", methods=["GET"])
def search():
    """
    Endpoint:

    /search?q=nome-do-filme
    """

    query = request.args.get("q", "").strip()

    if not query:
        return jsonify({
            "status": "error",
            "message": "É necessário indicar o parâmetro q."
        }), 400

    print()
    print("=" * 60)
    print(f"[SEARCH] Procurando: {query}")
    print("=" * 60)

    try:

        torrents = procurar_torrents(query)

        if not torrents:

            print("[SEARCH] Nenhum torrent encontrado.")

            return jsonify({
                "status": "not_found",
                "query": query,
                "message": "Nenhum torrent encontrado.",
                "results": []
            }), 404

        primeiro = torrents[0]

        print()
        print("[SEARCH] Melhor resultado:")
        print(f"         {primeiro['title']}")
        print(f"         Seeders: {primeiro['seeders']}")
        print()

        return jsonify({
            "status": "ok",
            "query": query,
            "torrent": primeiro,
            "results": torrents
        })

    except Exception as e:

        print()
        print("[ERROR] Erro ao pesquisar:")
        traceback.print_exc()

        return jsonify({
            "status": "error",
            "message": str(e)
        }), 500


if __name__ == "__main__":

    print("=" * 60)
    print("LUMFLIX - Torrent API")
    print("=" * 60)
    print(f"Servidor: http://{HOST}:{PORT}")
    print(f"Health:   http://{HOST}:{PORT}/health")
    print(f"Search:   http://{HOST}:{PORT}/search?q=FILME")
    print("=" * 60)
    print()

    app.run(
        host=HOST,
        port=PORT,
        debug=False
    )
    