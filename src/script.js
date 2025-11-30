/**
 * gLiTcH-Monitor Dashboard Script
 * Fetches and displays real-time server statistics
 */

async function fetchStats() {
    try {
        const response = await fetch('server-stats.php');
        const data = await response.json();

        // Update disk stats
        if (data.disk) {
            document.getElementById('disk-percent').textContent = data.disk.percent + '%';
            document.getElementById('disk-progress').style.width = data.disk.percent + '%';
            document.getElementById('disk-used').textContent = data.disk.used;
            document.getElementById('disk-total').textContent = data.disk.total;

            // Color code based on usage
            const diskProgress = document.getElementById('disk-progress');
            if (data.disk.percent >= 90) {
                diskProgress.style.background = 'linear-gradient(90deg, #ff4500, #ff6347)';
            } else if (data.disk.percent >= 75) {
                diskProgress.style.background = 'linear-gradient(90deg, #ffa500, #ffb347)';
            } else {
                diskProgress.style.background = 'linear-gradient(90deg, #32cd32, #00ff00)';
            }
        }

        // Update RAM stats
        if (data.memory) {
            document.getElementById('ram-percent').textContent = data.memory.percent + '%';
            document.getElementById('ram-progress').style.width = data.memory.percent + '%';
            document.getElementById('ram-used').textContent = data.memory.used;
            document.getElementById('ram-total').textContent = data.memory.total;

            // Color code based on usage
            const ramProgress = document.getElementById('ram-progress');
            if (data.memory.percent >= 90) {
                ramProgress.style.background = 'linear-gradient(90deg, #ff4500, #ff6347)';
            } else if (data.memory.percent >= 75) {
                ramProgress.style.background = 'linear-gradient(90deg, #ffa500, #ffb347)';
            } else {
                ramProgress.style.background = 'linear-gradient(90deg, #32cd32, #00ff00)';
            }
        }

        // Update CPU stats
        if (data.cpu) {
            document.getElementById('cpu-load').textContent = data.cpu.load;
            document.getElementById('cpu-progress').style.width = data.cpu.percent + '%';

            // Color code based on load
            const cpuProgress = document.getElementById('cpu-progress');
            if (data.cpu.percent >= 80) {
                cpuProgress.style.background = 'linear-gradient(90deg, #ff4500, #ff6347)';
            } else if (data.cpu.percent >= 50) {
                cpuProgress.style.background = 'linear-gradient(90deg, #ffa500, #ffb347)';
            } else {
                cpuProgress.style.background = 'linear-gradient(90deg, #32cd32, #00ff00)';
            }
        }

        // Update uptime
        if (data.uptime) {
            document.getElementById('uptime').textContent = data.uptime.formatted;
            document.getElementById('uptime-detailed').textContent = data.uptime.detailed;
        }

        // Update network/IP info
        if (data.network) {
            document.getElementById('private-ip').textContent = data.network.private_ip;
            document.getElementById('public-ip').textContent = data.network.public_ip;
            document.getElementById('network-rx').textContent = '↓ ' + data.network.rx;
            document.getElementById('network-tx').textContent = '↑ ' + data.network.tx;
        }

        // Update services count
        if (data.services) {
            document.getElementById('services-count').textContent = data.services.count;
        }

        // Update system info
        if (data.system) {
            document.getElementById('os-version').textContent = data.system.os;
            document.getElementById('kernel-version').textContent = 'Kernel: ' + data.system.kernel;
        }

        // Update process count
        if (data.processes) {
            document.getElementById('process-count').textContent = data.processes.total;
        }

        // Update temperature
        if (data.temperature) {
            document.getElementById('temperature').textContent = data.temperature.value;
        }

        // Update last update time
        const now = new Date();
        const timeStr = now.toLocaleTimeString();
        document.getElementById('last-update').textContent = timeStr;

    } catch (error) {
        console.error('Error fetching stats:', error);
        // Show error in last update field
        document.getElementById('last-update').textContent = 'Error loading data';
    }
}

// Initial load
fetchStats();

// Auto-refresh every 5 seconds
setInterval(fetchStats, 5000);
