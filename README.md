# lolankoa

## AI Task Assistant
Connect your task list and task history with an AI to keep things organized. 

## Features
Convert your input text into reasonable chunks of work with a suggested ordering of tasks in a sequence of events that makes sense and adapts to changes in your tasks.   

Various signals about the task will be stored and contextualized for the AI model. 

Your task history and the context awareness of your notes about upcoming things yhou have to work on will be continously analyzed to help suggest solutions to  various problems identified in your input. 

When you update the status of a task, upcoming tasks may be shuffled around depending on context clues gathered from the task history and new learnings that may have emerged about your tasks.

Every time the AI model look at the data it is trying to uncover and document patterns in the task activty that can be used to further optimize the task list. 

The combination of signal intelligence, pattern recognition, and a dynamic context window will result in a novel approach to producing and evolving a task list based on an individual. 

## Installation

### Database setup
Make your .env by copying the provided template
```
copy .env.example .env
```
SQLite is the easy default. But you can modify .env to use any other db service you wish from the many available options: link to provders? 

### AI Model setup
Depending on the AI provider you want to use to process tasks, you have several options. 
For each relevant provider, add the API key and connection info as shown:
|AI Provider|.env key|
... tbd
