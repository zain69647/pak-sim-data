const UPSTREAM_API = "https://wasifali-sim-info.netlify.app/api/search?phone=";

function extractRecords(payload) {
  if (!payload || !Array.isArray(payload.records) || payload.records.length === 0) {
    return [];
  }

  return payload.records;
}

function normalizeRecord(record) {
  return {
    Name: record.Name || record.NAME || record.name || "N/A",
    CNIC: record.CNIC || record.cnic || record.id || "N/A",
    Phone: record.Mobile || record.NUMBER || record.phone || "N/A",
    Network: record.Network || record.NETWORK || "Unknown",
    Address: record.Address || record.ADDRESS || record.address || "N/A",
  };
}

function extractCnic(record) {
  return String(record.CNIC || record.cnic || record.id || "").replace(/\D/g, "");
}

async function fetchSimRecords(value) {
  const response = await fetch(`${UPSTREAM_API}${encodeURIComponent(value)}`, {
    headers: {
      "User-Agent": "Mozilla/5.0",
      Accept: "application/json",
    },
  });

  if (!response.ok) {
    throw new Error(`Upstream API returned ${response.status}`);
  }

  const payload = await response.json();
  return extractRecords(payload);
}

module.exports = async function handler(req, res) {
  res.setHeader("Cache-Control", "no-store");

  if (req.method !== "GET") {
    return res.status(405).json({ error: "Method not allowed" });
  }

  const query = String(req.query.query || "").trim();

  if (!/^[0-9]{10,13}$/.test(query)) {
    return res.status(400).json({
      error: "Please enter a valid phone number or CNIC using digits only.",
    });
  }

  try {
    const isCnic = query.length === 13;
    let records = await fetchSimRecords(query);
    let note = null;

    if (!isCnic && records.length > 0) {
      const linkedCnic = extractCnic(records[0]);

      if (/^[0-9]{13}$/.test(linkedCnic)) {
        const cnicRecords = await fetchSimRecords(linkedCnic);

        if (cnicRecords.length > 0) {
          records = cnicRecords;
          note = `Showing all available records linked to CNIC ${linkedCnic}.`;
        } else {
          note = "Showing the phone record found. No additional records were available for its CNIC.";
        }
      }
    }

    if (records.length === 0) {
      return res.status(404).json({ error: "No records found for this search." });
    }

    return res.status(200).json({
      records: records.map(normalizeRecord),
      note,
    });
  } catch (error) {
    return res.status(502).json({
      error: "Unable to connect to the database. Please try again later.",
    });
  }
};
