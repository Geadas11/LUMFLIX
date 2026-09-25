import express from "express";
import WebTorrent from "webtorrent";
import MemoryChunkStore from "memory-chunk-store";
import { spawn } from "child_process";
import ffmpegPath from "ffmpeg-static";

const app = express();

const PORT = 3000;

const client = new WebTorrent({
    maxConns: 80
});

const torrents = new Map();


// ============================================================
// FUNÇÕES
// ============================================================
function precisaConversao(file) {
    const nome = file.name.toLowerCase();

    // Formatos que normalmente podem ser enviados diretamente
    if (
        nome.endsWith(".mp4") ||
        nome.endsWith(".webm")
    ) {
        return false;
    }

    // Tudo o resto passa pelo FFmpeg
    return true;
}


function escolherVideo(files) {
    const extensoes = [
        ".mp4",
        ".webm",
        ".m4v",
        ".mov",
        ".mkv",
        ".avi",
        ".ts",
        ".m2ts",
        ".mpeg",
        ".mpg"
    ];

    const videos = files.filter(file => {
        const nome = file.name.toLowerCase();

        return extensoes.some(ext => nome.endsWith(ext));
    });

    if (videos.length === 0) {
        return null;
    }

    // Preferir MP4
    const mp4 = videos.find(file =>
        file.name.toLowerCase().endsWith(".mp4")
    );

    if (mp4) {
        return mp4;
    }

    // Caso contrário, escolher o maior ficheiro de vídeo
    return videos.reduce((maior, atual) => {
        return atual.length > maior.length ? atual : maior;
    });
}


function mimeType(nome) {

    const ext =
        nome.toLowerCase().split(".").pop();

    switch (ext) {

        case "mp4":
        case "m4v":
            return "video/mp4";

        case "webm":
            return "video/webm";

        case "mov":
            return "video/quicktime";

        case "mkv":
            return "video/x-matroska";

        default:
            return "application/octet-stream";
    }
}


function novoId() {

    return (
        Date.now().toString(36) +
        Math.random()
            .toString(36)
            .substring(2, 10)
    );
}


// ============================================================
// STATUS
// ============================================================

app.get("/status/:id", (req, res) => {

    const item =
        torrents.get(req.params.id);

    if (!item) {

        return res.status(404).json({
            status: "error",
            error: "Torrent não encontrado."
        });
    }

    const torrent = item.torrent;

    res.json({

        status: "ok",

        id: item.id,

        ready: torrent.ready,

        done: torrent.done,

        progress: torrent.progress,

        peers: torrent.numPeers,

        downloaded: torrent.downloaded,

        downloadSpeed: torrent.downloadSpeed,

        uploadSpeed: torrent.uploadSpeed,

        video: item.file
            ? {
                name: item.file.name,
                size: item.file.length,
                type: mimeType(item.file.name)
            }
            : null
    });
});


// ============================================================
// INICIAR TORRENT
// ============================================================

app.post("/start", express.json(), async (req, res) => {

    try {

        const magnet =
            req.body?.magnet;

        if (
            !magnet ||
            !magnet.startsWith("magnet:")
        ) {

            return res.status(400).json({
                status: "error",
                error: "Magnet inválido."
            });
        }


        // Verificar se já existe

        for (const item of torrents.values()) {

            if (item.magnet === magnet) {

                return res.json({
                    status: "ok",
                    id: item.id,
                    existing: true
                });
            }
        }


        const id = novoId();


        // ====================================================
        // MEMORY STORE
        // ====================================================

        const torrent =
            client.add(
                magnet,
                {
                    store: MemoryChunkStore,
                    strategy: "sequential"
                }
            );


        const item = {

            id,

            magnet,

            torrent,

            file: null,

            created: Date.now()
        };


        torrents.set(id, item);


        // ====================================================
        // METADATA
        // ====================================================

        torrent.on("ready", () => {

            console.log(
                `[${id}] Metadata recebida`
            );


            item.file =
                escolherVideo(torrent.files);


            if (!item.file) {

                console.error(
                    `[${id}] Nenhum vídeo encontrado`
                );

                return;
            }


            console.log(
                `[${id}] Vídeo: ${item.file.name}`
            );


            console.log(
                `[${id}] Tamanho: ${item.file.length} bytes`
            );
        });


        // ====================================================
        // ERROS
        // ====================================================

        torrent.on("error", error => {

            console.error(
                `[${id}] Torrent error:`,
                error
            );
        });


        // ====================================================
        // DOWNLOAD
        // ====================================================

        torrent.on("download", bytes => {

            const progresso =
                (torrent.progress * 100).toFixed(2);

            console.log(
                `[${id}] ${progresso}% | ` +
                `${torrent.numPeers} peers | ` +
                `${torrent.downloadSpeed} B/s`
            );
        });


        res.json({

            status: "ok",

            id
        });

    } catch (error) {

        console.error(error);

        res.status(500).json({

            status: "error",

            error: error.message
        });
    }
});


// ============================================================
// STREAM
// ============================================================

app.get("/stream/:id", async (req, res) => {
    const item = torrents.get(req.params.id);

    if (!item || !item.file) {
        return res.status(404).json({
            status: "error",
            error: "Torrent ou vídeo não encontrado."
        });
    }

    const file = item.file;

    console.log(
        `[${item.id}] Stream pedido: ${file.name}`
    );

    // =========================================================
    // STREAM DIRETO
    // =========================================================

    if (!precisaConversao(file)) {
        const range = req.headers.range;

        if (!range) {
            res.status(200);
            res.setHeader("Content-Type", mimeType(file.name));
            res.setHeader("Content-Length", file.length);
            res.setHeader("Accept-Ranges", "bytes");
            res.setHeader("Cache-Control", "no-store");

            const stream = file.createReadStream();

            stream.on("error", err => {
                console.error(
                    `[${item.id}] Erro no stream:`,
                    err
                );

                if (!res.headersSent) {
                    res.status(500);
                }

                res.end();
            });

            stream.pipe(res);

            return;
        }

        const match = range.match(/bytes=(\d*)-(\d*)/);

        if (!match) {
            return res.status(416).end();
        }

        const start = match[1]
            ? parseInt(match[1], 10)
            : 0;

        const end = match[2]
            ? parseInt(match[2], 10)
            : file.length - 1;

        if (
            start >= file.length ||
            end >= file.length ||
            start > end
        ) {
            res.status(416);
            res.setHeader(
                "Content-Range",
                `bytes */${file.length}`
            );
            return res.end();
        }

        const tamanho = end - start + 1;

        res.status(206);

        res.setHeader(
            "Content-Type",
            mimeType(file.name)
        );

        res.setHeader(
            "Content-Range",
            `bytes ${start}-${end}/${file.length}`
        );

        res.setHeader(
            "Accept-Ranges",
            "bytes"
        );

        res.setHeader(
            "Content-Length",
            tamanho
        );

        res.setHeader(
            "Cache-Control",
            "no-store"
        );

        const stream = file.createReadStream({
            start,
            end
        });

        stream.on("error", err => {
            console.error(
                `[${item.id}] Erro no stream:`,
                err
            );

            res.destroy(err);
        });

        stream.pipe(res);

        return;
    }

    // =========================================================
    // CONVERSÃO COM FFMPEG
    // =========================================================

    console.log(
        `[${item.id}] Formato incompatível para browser.`
    );

    console.log(
        `[${item.id}] A iniciar FFmpeg...`
    );

    /*
     * O WebTorrent fornece o ficheiro diretamente ao FFmpeg.
     *
     * Não precisamos de guardar o filme inteiro no disco.
     *
     * FFmpeg:
     *
     * MKV/AVI/MOV/etc
     *        ↓
     *      H.264
     *        +
     *       AAC
     *        ↓
     *   fragmented MP4
     */

    res.status(200);

    res.setHeader(
        "Content-Type",
        "video/mp4"
    );

    res.setHeader(
        "Transfer-Encoding",
        "chunked"
    );

    res.setHeader(
        "Cache-Control",
        "no-store"
    );

    res.setHeader(
        "Accept-Ranges",
        "none"
    );

    const input = file.createReadStream();

    /*
     * Primeiro tentamos copiar o vídeo.
     *
     * Como o teu Spider-Man é x264/H.264,
     * não precisamos de recodificar o vídeo.
     *
     * O áudio é convertido para AAC para
     * maximizar compatibilidade.
     */

    const ffmpeg = spawn(ffmpegPath, [
        "-hide_banner",
        "-loglevel", "warning",

        "-i", "pipe:0",

        "-map", "0:v:0",
        "-map", "0:a:0?",

        "-c:v", "copy",
        "-c:a", "aac",
        "-b:a", "192k",

        "-movflags",
        "frag_keyframe+empty_moov+default_base_moof",

        "-f", "mp4",

        "pipe:1"
    ]);

    input.pipe(ffmpeg.stdin);

    ffmpeg.stdout.pipe(res);

    ffmpeg.stderr.on("data", data => {
        console.log(
            `[${item.id}] FFmpeg: ${data.toString().trim()}`
        );
    });

    ffmpeg.on("error", err => {
        console.error(
            `[${item.id}] Erro FFmpeg:`,
            err
        );

        if (!res.headersSent) {
            res.status(500);
        }

        res.end();
    });

    ffmpeg.on("close", code => {
        console.log(
            `[${item.id}] FFmpeg terminou. Código: ${code}`
        );
    });

    input.on("error", err => {
        console.error(
            `[${item.id}] Erro no ficheiro WebTorrent:`,
            err
        );

        ffmpeg.kill("SIGKILL");

        if (!res.destroyed) {
            res.destroy(err);
        }
    });

    req.on("close", () => {
        if (!res.writableEnded) {
            console.log(
                `[${item.id}] Browser fechou o stream.`
            );

            input.destroy();

            ffmpeg.kill("SIGKILL");
        }
    });
});


// ============================================================
// REMOVER TORRENT
// ============================================================

app.delete("/torrent/:id", async (req, res) => {

    const item =
        torrents.get(req.params.id);


    if (!item) {

        return res.status(404).json({

            status: "error",

            error: "Torrent não encontrado."
        });
    }


    try {

        await item.torrent.destroy();

        torrents.delete(req.params.id);


        res.json({

            status: "ok"
        });

    } catch (error) {

        res.status(500).json({

            status: "error",

            error: error.message
        });
    }
});


// ============================================================
// LIMPEZA AUTOMÁTICA
// ============================================================

setInterval(async () => {

    const agora =
        Date.now();


    for (const [id, item] of torrents) {

        // 2 horas

        if (
            agora - item.created >
            2 * 60 * 60 * 1000
        ) {

            console.log(
                `[${id}] A remover torrent antigo`
            );


            try {

                await item.torrent.destroy();

            } catch (e) {

                console.error(e);
            }


            torrents.delete(id);
        }
    }

}, 10 * 60 * 1000);


// ============================================================
// ERROS WEBTORRENT
// ============================================================

client.on("error", error => {

    console.error(
        "WebTorrent fatal error:",
        error
    );
});


// ============================================================
// SERVER
// ============================================================

app.listen(
    PORT,
    "127.0.0.1",
    () => {

        console.log(
            "LUMFLIX Torrent Server"
        );

        console.log(
            `http://127.0.0.1:${PORT}`
        );
    }
);