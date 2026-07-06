import argparse
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from tkinter import Tk, filedialog


def pick_folder(description: str) -> dict:
    root = Tk()
    root.withdraw()
    root.attributes("-topmost", True)
    try:
        path = filedialog.askdirectory(title=description or "Wybierz folder", mustexist=True)
    finally:
        root.destroy()

    if not path:
        return {"cancelled": True}

    return {"cancelled": False, "path": path}


def pick_file(title: str, filetypes: list[tuple[str, str]]) -> dict:
    root = Tk()
    root.withdraw()
    root.attributes("-topmost", True)
    try:
        path = filedialog.askopenfilename(
            title=title or "Wybierz plik",
            filetypes=filetypes or [("Wszystkie pliki", "*.*")]
        )
    finally:
        root.destroy()

    if not path:
        return {"cancelled": True}

    return {"cancelled": False, "path": path}


def parse_filetypes(raw_filter: str) -> list[tuple[str, str]]:
    if not raw_filter:
        return [("Wszystkie pliki", "*.*")]

    parts = [part for part in raw_filter.split("|") if part]
    pairs: list[tuple[str, str]] = []
    index = 0
    while index + 1 < len(parts):
        pairs.append((parts[index], parts[index + 1]))
        index += 2

    return pairs or [("Wszystkie pliki", "*.*")]


class HelperHandler(BaseHTTPRequestHandler):
    server_version = "AgentKsefHelper/1.0"

    def end_headers(self) -> None:
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        super().end_headers()

    def log_message(self, format: str, *args) -> None:
        return

    def send_json(self, status_code: int, payload: dict) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def read_json(self) -> dict:
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0:
            return {}

        raw = self.rfile.read(length)
        if not raw:
            return {}

        try:
            data = json.loads(raw.decode("utf-8"))
        except json.JSONDecodeError:
            return {}

        return data if isinstance(data, dict) else {}

    def do_OPTIONS(self) -> None:
        try:
            self.send_response(204)
            self.end_headers()
        except Exception as error:
            self.send_json(500, {"ok": False, "message": str(error)})

    def do_GET(self) -> None:
        try:
            if self.path == "/health":
                self.send_json(200, {"ok": True, "status": "ok", "port": self.server.server_port})
                return

            self.send_json(404, {"ok": False, "message": "Nieznany endpoint helpera."})
        except Exception as error:
            self.send_json(500, {"ok": False, "message": str(error)})

    def do_POST(self) -> None:
        try:
            if self.path == "/pick-folder":
                body = self.read_json()
                description = str(body.get("description") or "Wybierz folder")
                result = pick_folder(description)
                self.send_json(200, {"ok": True, **result})
                return

            if self.path == "/pick-file":
                body = self.read_json()
                title = str(body.get("title") or "Wybierz plik")
                filetypes = parse_filetypes(str(body.get("filter") or ""))
                result = pick_file(title, filetypes)
                self.send_json(200, {"ok": True, **result})
                return

            self.send_json(404, {"ok": False, "message": "Nieznany endpoint helpera."})
        except Exception as error:
            self.send_json(500, {"ok": False, "message": str(error)})


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()

    server = HTTPServer(("127.0.0.1", args.port), HelperHandler)
    print(f"Agent KSeF local helper starting on port {args.port}")
    print("Available endpoints: GET /health, POST /pick-folder, POST /pick-file")
    server.serve_forever()


if __name__ == "__main__":
    main()
