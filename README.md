# Regional Carpooling Platform (EMC Challenge)

### 1. AI Tools Used
- **Claude AI**: Helped define the business logic, design the data models, and set up the overall project architecture.
- **Gemini**: Used for writing the code, implementing features, and debugging errors during development.

### 2. Submission Contents
- **Source Code**: Full Symfony application including Docker setup, database migrations, seed data, and tests.
- **Documentation**: Setup instructions (`INSTALL.md`) and environment templates (`.env.example`).
- **Prompt History**: A PDF export containing the full conversation with Gemini used to build and debug the app.

### 3. Key Takeaways
- **Where AI saved time**: Coding was much faster since I am not very familiar with PHP and Symfony. It helped generate boilerplate, entity structures, and syntax quickly.
- **Where AI made mistakes**: It struggled mainly when integrating new features into existing code (for example, wiring OpenRouteService with our existing entities and handling unexpected API responses).
- **How I caught them**: I tested the platform using predefined scenarios and edge cases, alongside PHPUnit tests, to verify that existing features still worked properly.
